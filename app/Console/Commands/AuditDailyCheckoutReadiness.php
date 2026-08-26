<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Enums\DailyCheckoutRequirement;
use App\Models\Apparatus;
use App\Services\DailyCheckoutChecklistResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use stdClass;

/**
 * Read-only deployment gate for Daily Truck Checkout policy and checklist data.
 *
 * It intentionally does not infer a policy from status, type, station, or prior
 * inspections. Approved family mappings are visible in the matrix, but specialty
 * units remain blocked until an authorized owner chooses a tracked template.
 */
final class AuditDailyCheckoutReadiness extends Command
{
    protected $signature = 'daily-checkout:audit
                            {--json : Emit the apparatus matrix as JSON}';

    protected $description = 'Read-only gate for Daily Checkout policy classification, templates, routing, and checklist coverage.';

    public function handle(DailyCheckoutChecklistResolver $checklists): int
    {
        $hasDailyCheckoutRequirement = Schema::hasColumn('apparatuses', 'daily_checkout_requirement');
        $hasDailyCheckoutTemplate = Schema::hasColumn('apparatuses', 'daily_checkout_template');
        /** @var list<string|Expression> $columns */
        $columns = [
            'apparatuses.id',
            'apparatuses.station_id',
            'stations.station_number as station_number',
            'stations.name as station_name',
            'apparatuses.unit_id',
            'apparatuses.name',
            'apparatuses.type',
            'apparatuses.class_description',
            'apparatuses.designation',
            'apparatuses.slug',
            'apparatuses.status',
            $hasDailyCheckoutRequirement
                ? 'apparatuses.daily_checkout_requirement'
                : DB::raw('NULL as daily_checkout_requirement'),
            $hasDailyCheckoutTemplate
                ? 'apparatuses.daily_checkout_template'
                : DB::raw('NULL as daily_checkout_template'),
        ];

        /** @var list<stdClass> $apparatuses */
        $apparatuses = DB::table('apparatuses')
            ->leftJoin('stations', 'stations.id', '=', 'apparatuses.station_id')
            ->select($columns)
            ->orderBy('apparatuses.id')
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
            'explicitly_non_required' => 0,
            'unknown_policy' => 0,
            'invalid_policy' => 0,
            'pending_template' => 0,
            'configured_template' => 0,
            'invalid_template' => 0,
            'ambiguous_classification' => 0,
            'issues' => 0,
        ];
        $matrix = [];

        foreach ($apparatuses as $apparatus) {
            $rawPolicy = $this->normalizedRawPolicy($apparatus);
            $issues = [];

            if (! $hasDailyCheckoutRequirement) {
                $policy = DailyCheckoutRequirement::Unknown;
                $policyValue = $policy->value;
                $summary['unknown_policy']++;
                $issues[] = 'daily_checkout_requirement_schema_absent';
            } else {
                $policy = DailyCheckoutRequirement::tryFrom($rawPolicy);
                if ($policy === null) {
                    $summary['invalid_policy']++;
                    $issues[] = 'daily_checkout_requirement_invalid';
                    $policyValue = DailyCheckoutRequirement::Unknown->value;
                } else {
                    $policyValue = $policy->value;
                }

                if ($policy === null || $policy === DailyCheckoutRequirement::Unknown) {
                    if ($policy !== null) {
                        $summary['unknown_policy']++;
                        $issues[] = 'daily_checkout_requirement_unknown';
                    }
                } elseif ($policy === DailyCheckoutRequirement::Required) {
                    $summary['required']++;
                } else {
                    $summary['explicitly_non_required']++;
                }
            }

            if (! $hasDailyCheckoutTemplate) {
                $issues[] = 'daily_checkout_template_schema_absent';
            }

            $model = new Apparatus;
            $model->setRawAttributes((array) $apparatus, true);
            $resolution = $checklists->resolve($model);

            if ($resolution['configured_template'] === DailyCheckoutChecklistTemplate::Pending->value) {
                $summary['pending_template']++;
            } else {
                $summary['configured_template']++;
            }

            if ($resolution['error'] === 'invalid_checklist_template') {
                $summary['invalid_template']++;
                if ($policy !== DailyCheckoutRequirement::Required) {
                    $issues[] = 'daily_checkout_template_invalid';
                }
            }

            if (str_starts_with((string) $resolution['error'], 'ambiguous_')) {
                $summary['ambiguous_classification']++;
            }

            $slug = trim((string) ($apparatus->slug ?? ''));
            if ($policy === DailyCheckoutRequirement::Required) {
                if ($slug === '') {
                    $issues[] = 'required_apparatus_slug_missing';
                } elseif (($requiredSlugCounts[$slug] ?? 0) > 1) {
                    $issues[] = 'required_apparatus_slug_duplicate';
                }

                match ($resolution['error']) {
                    'specialty_template_pending' => $issues[] = 'required_apparatus_checklist_specialty_pending',
                    'unclassified_family_template_pending' => $issues[] = 'required_apparatus_checklist_family_unclassified',
                    'invalid_checklist_template' => $issues[] = 'required_apparatus_checklist_template_invalid',
                    'ambiguous_apparatus_family', 'ambiguous_apparatus_identity' => $issues[] = 'required_apparatus_checklist_ambiguous',
                    default => ! $resolution['usable'] ? $issues[] = 'required_apparatus_checklist_unusable' : null,
                };
            }

            $summary['issues'] += count($issues);
            $matrix[] = [
                'apparatus_id' => (int) $apparatus->id,
                'station_id' => $apparatus->station_id === null ? null : (int) $apparatus->station_id,
                'station' => [
                    'number' => $apparatus->station_number === null ? null : (string) $apparatus->station_number,
                    'name' => $apparatus->station_name,
                ],
                'unit_id' => $apparatus->unit_id,
                'designation' => $apparatus->designation,
                'name' => $apparatus->name,
                'type' => $apparatus->type,
                'class_description' => $apparatus->class_description,
                'status' => $apparatus->status,
                'slug' => $apparatus->slug,
                'checkout_url' => $policy === DailyCheckoutRequirement::Required && $slug !== '' ? "/daily/vehicle-inspections/{$slug}" : null,
                'daily_checkout_requirement' => $policyValue,
                'daily_checkout_template' => $resolution['configured_template'],
                'proposed_checklist_template' => $resolution['checklist_type'] === 'unmapped' ? null : $resolution['checklist_type'],
                'resolution_source' => $resolution['resolution_source'],
                'family' => $resolution['family'],
                'identity' => $resolution['identity'],
                'ambiguity' => $resolution['ambiguity'],
                'checklist' => [
                    'item_count' => $resolution['item_count'],
                    'usable' => $resolution['usable'],
                    'error' => $resolution['error'],
                ],
                'issues' => $issues,
            ];
        }

        $report = [
            'schema_version' => 3,
            'generated_at_utc' => now('UTC')->toIso8601String(),
            'read_only' => true,
            'schema' => [
                'daily_checkout_requirement_column_present' => $hasDailyCheckoutRequirement,
                'daily_checkout_template_column_present' => $hasDailyCheckoutTemplate,
            ],
            'gate_passed' => $summary['issues'] === 0,
            'summary' => $summary,
            'apparatus' => $matrix,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->line('Daily Checkout data gate: '.($report['gate_passed'] ? 'PASSED' : 'BLOCKED'));
            $this->table(
                ['Total', 'Required', 'Explicitly non-required', 'Unknown policy', 'Pending template', 'Invalid template', 'Issues'],
                [[
                    $summary['total'],
                    $summary['required'],
                    $summary['explicitly_non_required'],
                    $summary['unknown_policy'],
                    $summary['pending_template'],
                    $summary['invalid_template'],
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
