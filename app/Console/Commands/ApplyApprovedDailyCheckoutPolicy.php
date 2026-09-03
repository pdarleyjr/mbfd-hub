<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Apparatus;
use App\Models\Station;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class ApplyApprovedDailyCheckoutPolicy extends Command
{
    protected $signature = 'daily-checkout:apply-approved-policy
                            {--dry-run : Validate and report without mutation}
                            {--confirm= : Must equal APPLY_APPROVED_FRONTLINE_DAILY_POLICY}';

    protected $description = 'Apply the owner-approved frontline Daily Checkout requirement and template mapping.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && $this->option('confirm') !== 'APPLY_APPROVED_FRONTLINE_DAILY_POLICY') {
            $this->error('Daily Checkout policy apply blocked: exact confirmation was not provided.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(fn (): array => $this->apply($dryRun), 3);
        } catch (RuntimeException $exception) {
            $this->error('Daily Checkout policy apply blocked: '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            Log::error('daily_checkout_approved_policy_failed', ['exception_class' => $exception::class]);
            $this->error('Daily Checkout policy apply failed and no partial transaction was retained.');

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** @return array<string, int|string|bool> */
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
            if (count($matches) > 1) {
                throw new RuntimeException("{$unitId} resolves to multiple apparatus records.");
            }

            /** @var Apparatus|null $target */
            $target = $matches === [] ? null : array_values($matches)[0];
            if ($target !== null && $target->getAttribute('unit_id') !== $unitId) {
                throw new RuntimeException("{$unitId} collides with an alias on apparatus {$target->id}.");
            }
            if ($target === null && $unitId !== 'FB6') {
                throw new RuntimeException("Required apparatus {$unitId} was not found by exact identity.");
            }
            if ($target !== null && $target->station_id !== $stations[$entry['station']]->id) {
                throw new RuntimeException("{$unitId} is not assigned to approved Station {$entry['station']}.");
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

        return $result;
    }

    private function normalize(mixed $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value)) ?? '';
    }
}
