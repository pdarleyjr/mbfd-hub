<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DailyCheckoutRequirement;
use App\Models\Apparatus;
use App\Services\DailyCheckoutChecklistResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use stdClass;

/**
 * Read-only deployment gate for Daily Truck Checkout policy and checklist data.
 *
 * It intentionally does not infer an apparatus policy from status, type, station,
 * or historical inspections. Unknown records must be classified by an authorized
 * operational owner before a Daily Checkout release is accepted.
 */
final class AuditDailyCheckoutReadiness extends Command
{
    protected $signature = 'daily-checkout:audit
                            {--json : Emit the apparatus matrix as JSON}';

    protected $description = 'Read-only gate for Daily Checkout policy classification, routing, and checklist coverage.';

    public function handle(DailyCheckoutChecklistResolver $checklists): int
    {
        /** @var list<stdClass> $apparatuses */
        $apparatuses = DB::table('apparatuses')
            ->select([
                'id',
                'station_id',
                'unit_id',
                'name',
                'type',
                'designation',
                'slug',
                'status',
                'daily_checkout_requirement',
            ])
            ->orderBy('id')
            ->get()
            ->all();

        $requiredSlugCounts = [];
        foreach ($apparatuses as $apparatus) {
            if ($this->policyFor($apparatus) !== DailyCheckoutRequirement::Required) {
                continue;
            }

            $slug = trim((string) ($apparatus->slug ?? ''));
            if ($slug !== '') {
                $requiredSlugCounts[$slug] = ($requiredSlugCounts[$slug] ?? 0) + 1;
            }
        }

        $summary = [
            'total' => count($apparatuses),
            'required' => 0,
            'explicitly_exempt' => 0,
            'unknown' => 0,
            'invalid_policy' => 0,
            'issues' => 0,
        ];
        $matrix = [];

        foreach ($apparatuses as $apparatus) {
            $rawPolicy = $this->normalizedRawPolicy($apparatus);
            $policy = DailyCheckoutRequirement::tryFrom($rawPolicy);
            $issues = [];
            $checklist = null;

            if ($policy === null) {
                $summary['invalid_policy']++;
                $issues[] = 'daily_checkout_requirement_invalid';
                $policyValue = DailyCheckoutRequirement::Unknown->value;
            } else {
                $policyValue = $policy->value;
            }

            if ($policy === null || $policy === DailyCheckoutRequirement::Unknown) {
                if ($policy !== null) {
                    $summary['unknown']++;
                    $issues[] = 'daily_checkout_requirement_unknown';
                }
            } elseif ($policy === DailyCheckoutRequirement::Required) {
                $summary['required']++;
                $slug = trim((string) ($apparatus->slug ?? ''));

                if ($slug === '') {
                    $issues[] = 'required_apparatus_slug_missing';
                } elseif (($requiredSlugCounts[$slug] ?? 0) > 1) {
                    $issues[] = 'required_apparatus_slug_duplicate';
                }

                $model = new Apparatus;
                $model->setRawAttributes((array) $apparatus, true);
                $resolution = $checklists->resolve($model);
                $checklist = [
                    'type' => $resolution['checklist_type'],
                    'item_count' => $resolution['item_count'],
                    'usable' => $resolution['usable'],
                    'error' => $resolution['error'],
                ];

                if ($resolution['error'] === 'unmapped_specialized_apparatus') {
                    $issues[] = 'required_apparatus_checklist_unmapped';
                } elseif ($resolution['error'] === 'ambiguous_specialized_apparatus') {
                    $issues[] = 'required_apparatus_checklist_ambiguous';
                } elseif (! $resolution['usable']) {
                    $issues[] = 'required_apparatus_checklist_unusable';
                }
            } else {
                $summary['explicitly_exempt']++;
            }

            $summary['issues'] += count($issues);
            $matrix[] = [
                'apparatus_id' => (int) $apparatus->id,
                'station_id' => $apparatus->station_id === null ? null : (int) $apparatus->station_id,
                'unit_id' => $apparatus->unit_id,
                'designation' => $apparatus->designation,
                'name' => $apparatus->name,
                'type' => $apparatus->type,
                'status' => $apparatus->status,
                'slug' => $apparatus->slug,
                'daily_checkout_requirement' => $policyValue,
                'checklist' => $checklist,
                'issues' => $issues,
            ];
        }

        $report = [
            'schema_version' => 1,
            'generated_at_utc' => now('UTC')->toIso8601String(),
            'read_only' => true,
            'gate_passed' => $summary['issues'] === 0,
            'summary' => $summary,
            'apparatus' => $matrix,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->line('Daily Checkout data gate: '.($report['gate_passed'] ? 'PASSED' : 'BLOCKED'));
            $this->table(
                ['Total', 'Required', 'Explicitly exempt', 'Unknown', 'Invalid policy', 'Issues'],
                [[
                    $summary['total'],
                    $summary['required'],
                    $summary['explicitly_exempt'],
                    $summary['unknown'],
                    $summary['invalid_policy'],
                    $summary['issues'],
                ]],
            );

            foreach ($matrix as $row) {
                if ($row['issues'] !== []) {
                    $this->warn(sprintf(
                        'Apparatus %d (%s): %s',
                        $row['apparatus_id'],
                        $row['designation'] ?? $row['unit_id'] ?? 'unnamed',
                        implode(', ', $row['issues']),
                    ));
                }
            }
        }

        return $report['gate_passed'] ? self::SUCCESS : self::FAILURE;
    }

    private function policyFor(stdClass $apparatus): ?DailyCheckoutRequirement
    {
        return DailyCheckoutRequirement::tryFrom($this->normalizedRawPolicy($apparatus));
    }

    private function normalizedRawPolicy(stdClass $apparatus): string
    {
        return strtolower(trim((string) ($apparatus->daily_checkout_requirement ?? '')));
    }

    /** @param array<string, mixed> $report */
    private function encode(array $report): string
    {
        try {
            return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Could not encode the Daily Checkout audit report.', 0, $exception);
        }
    }
}
