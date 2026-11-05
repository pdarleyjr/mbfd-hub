<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Apparatus;
use App\Models\Station;
use App\Services\Fleet\FrontlineCoverage;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class ApplyApprovedDailyCheckoutPolicy extends Command
{
    protected $signature = 'daily-checkout:apply-approved-policy
                            {--dry-run : Validate and report without mutation}
                            {--verify : Fail unless the 14 approved units are configured and every other required unit is an In Service replacement for an approved unit that is Out of Service; never mutates}
                            {--confirm= : Must equal APPLY_APPROVED_FRONTLINE_DAILY_POLICY}';

    protected $description = 'Apply the owner-approved frontline Daily Checkout requirement and template mapping.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $verify = (bool) $this->option('verify');
        if ($dryRun && $verify) {
            $this->error('Daily Checkout policy verification blocked: choose either --dry-run or --verify.');

            return self::FAILURE;
        }
        if (! $dryRun && ! $verify && $this->option('confirm') !== 'APPLY_APPROVED_FRONTLINE_DAILY_POLICY') {
            $this->error('Daily Checkout policy apply blocked: exact confirmation was not provided.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(fn (): array => $this->apply($dryRun || $verify), 3);
        } catch (RuntimeException $exception) {
            $this->error('Daily Checkout policy apply blocked: '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            Log::error('daily_checkout_approved_policy_failed', ['exception_class' => $exception::class]);
            $this->error('Daily Checkout policy apply failed and no partial transaction was retained.');

            return self::FAILURE;
        }

        $approvedIds = is_array($result['approved_ids']) ? $result['approved_ids'] : [];
        unset($result['approved_ids']);

        if ($verify) {
            $required = Apparatus::query()
                ->where('daily_checkout_requirement', 'required')
                ->get(['id', 'unit_id', 'status']);
            // Only the 14 approved units are required, except that a reserve unit put In Service to
            // cover an approved unit that is Out of Service also needs its Daily Checkout. Replacement
            // coverage is the only accepted extra: one In Service extra per approved unit out of service.
            $additional = $required->reject(fn (Apparatus $apparatus): bool => in_array((int) $apparatus->getKey(), $approvedIds, true));
            $replacementCapacity = $required
                ->filter(fn (Apparatus $apparatus): bool => in_array((int) $apparatus->getKey(), $approvedIds, true)
                    && $apparatus->getAttribute('status') === 'Out of Service')
                ->count();
            $additionalAreReplacements = $additional->every(fn (Apparatus $apparatus): bool => $apparatus->getAttribute('status') === 'In Service')
                && $additional->count() <= $replacementCapacity;
            $verified = $result['updated'] === 0
                && $result['created'] === 0
                && $result['already_configured'] === 14
                && $additionalAreReplacements;
            $result['status'] = $verified
                ? 'VERIFIED_APPROVED_FRONTLINE_DAILY_POLICY'
                : 'APPROVED_FRONTLINE_DAILY_POLICY_NOT_APPLIED';
            $result['verified'] = $verified;
            $result['required_count'] = $required->count();
            $result['additional_required'] = $additional->map(fn (Apparatus $apparatus): string => (string) $apparatus->getAttribute('unit_id'))->values()->all();
            $result['replacement_capacity'] = $replacementCapacity;
            $result['reasons'] = $verified ? [] : $this->verifyReasons($result, $additional, $replacementCapacity);
            // One JSON line: the deploy log keeps it, and the reasons say what to fix.
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $verified ? self::SUCCESS : self::FAILURE;
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** @return array<string, int|string|bool|list<int>> */
    private function apply(bool $dryRun): array
    {
        /** @var list<array{unit_id: string, station: int, template: string}> $policy */
        $policy = config('daily_checkout_frontline');
        if (count($policy) !== 14) {
            throw new RuntimeException('The approved policy must contain exactly 14 apparatus mappings.');
        }

        $stationNumbers = array_values(array_unique(array_column($policy, 'station')));
        $stations = Station::query()->whereIn('station_number', $stationNumbers)->get()->keyBy('station_number');
        foreach ($stationNumbers as $number) {
            if (! $stations->has($number)) {
                throw new RuntimeException("Station {$number} does not exist.");
            }
        }

        $apparatuses = Apparatus::query()->when(! $dryRun, fn ($query) => $query->lockForUpdate())->get();
        $aliases = [];
        foreach ($apparatuses as $apparatus) {
            foreach ([$apparatus->getAttribute('unit_id'), $apparatus->designation, $apparatus->name] as $value) {
                $alias = $this->normalize($value);
                if ($alias !== '') {
                    $aliases[$alias][$apparatus->id] = $apparatus;
                }
            }
        }

        $targets = [];
        foreach ($policy as $entry) {
            $unitId = $entry['unit_id'];
            $aliasKeys = $unitId === 'FB6' ? ['FB6', 'FIREBOAT6'] : [$this->normalize($unitId)];
            $matches = [];
            foreach ($aliasKeys as $aliasKey) {
                foreach ($aliases[$aliasKey] ?? [] as $id => $apparatus) {
                    $matches[$id] = $apparatus;
                }
            }
            // The same wording the Apparatus page warning uses (FrontlineCoverage), so an admin can act on it.
            if (count($matches) > 1) {
                throw new RuntimeException("{$unitId} resolves to multiple apparatus records. ".FrontlineCoverage::severalMessage($unitId, array_values($matches)));
            }

            /** @var Apparatus|null $target */
            $target = $matches === [] ? null : array_values($matches)[0];
            if ($target !== null && $target->getAttribute('unit_id') !== $unitId) {
                throw new RuntimeException("{$unitId} collides with an alias on apparatus {$target->id}. ".FrontlineCoverage::conflictMessage($unitId, $target));
            }
            if ($target === null && $unitId !== 'FB6') {
                throw new RuntimeException("Required apparatus {$unitId} was not found by exact identity. ".FrontlineCoverage::missingMessage($unitId));
            }
            if ($target !== null && $target->station_id !== $stations[$entry['station']]->id) {
                throw new RuntimeException("{$unitId} is not assigned to approved Station {$entry['station']}. ".FrontlineCoverage::stationMessage($unitId, (int) $entry['station'], $target));
            }
            $targets[$unitId] = [$entry, $target];
        }

        $updated = 0;
        $created = 0;
        $alreadyConfigured = 0;
        foreach ($targets as $unitId => [$entry, $target]) {
            if ($target === null) {
                if (! $dryRun) {
                    Apparatus::query()->create([
                        'station_id' => $stations[6]->id,
                        'unit_id' => 'FB6',
                        'designation' => 'FB6',
                        'name' => 'Fire Boat 6',
                        'type' => 'Fire Boat',
                        'slug' => 'fb6',
                        'status' => null,
                        'daily_checkout_requirement' => 'required',
                        'daily_checkout_template' => 'fireboat6',
                    ]);
                }
                $created++;

                continue;
            }

            $isConfigured = $target->getRawOriginal('daily_checkout_requirement') === 'required'
                && $target->getRawOriginal('daily_checkout_template') === $entry['template'];
            if ($isConfigured) {
                $alreadyConfigured++;

                continue;
            }
            if (! $dryRun) {
                $target->forceFill([
                    'daily_checkout_requirement' => 'required',
                    'daily_checkout_template' => $entry['template'],
                ])->save();
            }
            $updated++;
        }

        $result = [
            'status' => $dryRun ? 'DRY_RUN_VALID' : 'APPLIED_APPROVED_FRONTLINE_DAILY_POLICY',
            'dry_run' => $dryRun,
            'updated' => $updated,
            'created' => $created,
            'already_configured' => $alreadyConfigured,
        ];
        Log::notice('daily_checkout_approved_policy_evaluated', $result);

        $result['approved_ids'] = array_values(array_filter(array_map(
            static fn (array $pair): ?int => $pair[1] instanceof Apparatus ? (int) $pair[1]->getKey() : null,
            $targets,
        )));

        return $result;
    }

    /**
     * Why --verify failed, in words an admin can act on.
     *
     * @param  array<string, mixed>  $result
     * @param  Collection<int, Apparatus>  $additional
     * @return list<string>
     */
    private function verifyReasons(array $result, Collection $additional, int $replacementCapacity): array
    {
        $reasons = [];
        $pending = (int) $result['updated'] + (int) $result['created'];
        if ($pending > 0) {
            $reasons[] = "{$pending} of the 14 approved units are not set up for Daily Checkout yet. Run daily-checkout:apply-approved-policy --confirm=APPLY_APPROVED_FRONTLINE_DAILY_POLICY.";
        }
        $notInService = $additional->reject(fn (Apparatus $apparatus): bool => $apparatus->getAttribute('status') === 'In Service');
        foreach ($notInService as $apparatus) {
            $reasons[] = 'Record #'.$apparatus->getKey().' ('.($apparatus->getAttribute('unit_id') ?: 'no unit ID').') requires Daily Checkout but is not an approved unit and is not In Service. Set its Daily Checkout policy under More settings.';
        }
        if ($additional->count() > $replacementCapacity) {
            $names = $additional->map(fn (Apparatus $apparatus): string => (string) ($apparatus->getAttribute('unit_id') ?: '#'.$apparatus->getKey()))->implode(', ');
            $reasons[] = "These records require Daily Checkout but are not approved units: {$names}. Only {$replacementCapacity} approved ".($replacementCapacity === 1 ? 'unit is' : 'units are')
                .' Out of Service for them to cover. Use Swap truck to move a unit onto a truck instead, or set their Daily Checkout policy under More settings.';
        }

        return $reasons;
    }

    private function normalize(mixed $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value)) ?? '';
    }
}
