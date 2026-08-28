<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DailyCheckoutRequirement;
use App\Models\Apparatus;
use App\Models\DailyCheckoutLedgerCutover;
use App\Services\DailyCheckoutChecklistEvidenceInspector;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\DailyCheckoutComplianceService;
use App\Services\DailyCheckoutPreactivationManifest;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Read-only pre-activation gate for a disposable, named Daily Checkout
 * candidate snapshot. This command has no apply/backfill/migration mode.
 */
final class AuditDailyCheckoutPreactivation extends Command
{
    protected $signature = 'daily-checkout:preactivation
                            {--connection= : Named, disposable candidate database connection}
                            {--policy-manifest= : Owner-approved Daily Checkout policy JSON}
                            {--snapshot-id= : Immutable identifier for the staged snapshot}
                            {--as-of= : ISO-8601 assessment time for the snapshot}
                            {--candidate-sha= : Immutable candidate source SHA bound to the policy manifest}
                            {--allow-classification-required : Permit only unresolved classification holds for an owner beta}
                            {--json : Emit the complete preactivation report as JSON}';

    protected $description = 'Read-only pre-activation gate for a staged Daily Checkout candidate snapshot.';

    public function handle(
        DailyCheckoutPreactivationManifest $manifests,
        DailyCheckoutChecklistResolver $checklists,
        DailyCheckoutChecklistEvidenceInspector $checklistEvidence,
        DailyCheckoutComplianceService $compliance,
    ): int {
        $input = $this->input();
        if ($input['issues'] !== []) {
            return $this->emit($this->blockedReport($input, $input['issues']));
        }

        try {
            $manifest = $manifests->load(
                $input['policy_manifest'],
                $input['snapshot_id'],
                $input['as_of'],
                $input['candidate_sha'] ?? '',
            );
        } catch (RuntimeException $exception) {
            return $this->emit($this->blockedReport($input, [$exception->getMessage()]));
        }

        try {
            $connection = DB::connection($input['connection']);
            $report = $connection->transaction(function () use (
                $connection,
                $input,
                $manifest,
                $checklists,
                $checklistEvidence,
                $compliance,
            ): array {
                // PostgreSQL enforces the no-write contract at the database
                // transaction layer. Other drivers still execute SELECT/schema
                // inspection only; deployment must supply a read-only clone role.
                if ($connection->getDriverName() === 'pgsql') {
                    $connection->statement('SET TRANSACTION READ ONLY');
                }

                return $this->report(
                    $connection,
                    $input,
                    $manifest,
                    $checklists,
                    $checklistEvidence,
                    $compliance,
                );
            });
        } catch (Throwable) {
            return $this->emit($this->blockedReport($input, ['preactivation_audit_failed']));
        }

        return $this->emit($report);
    }

    /**
     * @return array{
     *     connection: string,
     *     policy_manifest: string,
     *     snapshot_id: string,
     *     as_of: CarbonImmutable,
     *     candidate_sha: string|null,
     *     allow_classification_required: bool,
     *     issues: list<string>
     * }
     */
    private function input(): array
    {
        $connection = $this->optionString('connection');
        $manifest = $this->optionString('policy-manifest');
        $snapshotId = $this->optionString('snapshot-id');
        $asOfValue = $this->optionString('as-of');
        $candidateSha = $this->optionString('candidate-sha');
        $allowClassificationRequired = (bool) $this->option('allow-classification-required');
        $issues = [];

        if ($connection === null) {
            $issues[] = 'preactivation_input_missing:connection';
        } elseif (str_contains($connection, '::')) {
            // Laravel resolves "name::read" and "name::write" aliases back to
            // the base connection. Never permit an alias to bypass the default
            // or candidate-only connection guard.
            $issues[] = 'preactivation_connection_alias_not_allowed';
        } elseif ($connection === (string) config('database.default')) {
            $issues[] = 'preactivation_connection_must_not_be_default';
        } else {
            $connectionConfig = config('database.connections.'.$connection);
            if (! is_array($connectionConfig)) {
                $issues[] = 'preactivation_connection_unconfigured';
            } else {
                if (($connectionConfig['daily_checkout_preactivation_candidate'] ?? false) !== true) {
                    $issues[] = 'preactivation_connection_not_authorized_candidate';
                }
                if (($connectionConfig['daily_checkout_preactivation_read_only'] ?? false) !== true) {
                    $issues[] = 'preactivation_connection_not_declared_read_only';
                }
            }
        }
        if ($manifest === null) {
            $issues[] = 'preactivation_input_missing:policy_manifest';
        }
        if ($snapshotId === null) {
            $issues[] = 'preactivation_input_missing:snapshot_id';
        }
        if ($asOfValue === null) {
            $issues[] = 'preactivation_input_missing:as_of';
        }
        if ($candidateSha === null) {
            $issues[] = 'preactivation_input_missing:candidate_sha';
        } elseif (preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/i', $candidateSha) !== 1) {
            $issues[] = 'preactivation_input_invalid:candidate_sha';
        } else {
            $candidateSha = strtolower($candidateSha);
        }

        $asOf = CarbonImmutable::now('UTC');
        if ($asOfValue !== null) {
            try {
                $asOf = CarbonImmutable::parse($asOfValue)->utc();
            } catch (Throwable) {
                $issues[] = 'preactivation_input_invalid:as_of';
            }
        }

        return [
            'connection' => $connection ?? '',
            'policy_manifest' => $manifest ?? '',
            'snapshot_id' => $snapshotId ?? '',
            'as_of' => $asOf,
            'candidate_sha' => $candidateSha,
            'allow_classification_required' => $allowClassificationRequired,
            'issues' => $issues,
        ];
    }

    /**
     * @param  array{connection: string, policy_manifest: string, snapshot_id: string, as_of: CarbonImmutable, candidate_sha: string|null, allow_classification_required: bool, issues: list<string>}  $input
     * @param  array{schema_version: int, snapshot_id: string, as_of_utc: CarbonImmutable, approval_reference: string, candidate_sha: string, sha256: string, apparatus: array<int, array<string, mixed>>, expected_absent: list<array<string, string>>}  $manifest
     * @return array<string, mixed>
     */
    private function report(
        Connection $connection,
        array $input,
        array $manifest,
        DailyCheckoutChecklistResolver $checklists,
        DailyCheckoutChecklistEvidenceInspector $checklistEvidence,
        DailyCheckoutComplianceService $compliance,
    ): array {
        $schema = $this->schema($input['connection']);
        $schemaIssues = [];
        foreach ($schema as $key => $present) {
            if (! $present) {
                $schemaIssues[] = "{$key}_schema_absent";
            }
        }

        /** @var list<stdClass> $apparatuses */
        $apparatuses = $this->apparatuses($connection, $schema)->all();
        $models = collect($apparatuses)->map(function (stdClass $apparatus) use ($input): Apparatus {
            $model = new Apparatus;
            $model->setConnection($input['connection']);
            $model->setRawAttributes((array) $apparatus, true);

            return $model;
        });
        $apparatusIds = $models->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        [$startOfDay, $startOfNextDay] = $this->localDayWindow($input['as_of']);

        $dailyCheckout = $schema['daily_checkout_requirement_column_present']
            && $schema['daily_checkout_template_column_present']
            && $schema['apparatus_operational_status_ledger_present']
            && $schema['daily_checkout_ledger_cutover_present']
            && $schema['apparatus_inspection_integrity_columns_present']
            ? $compliance->summaryForApparatuses(
                $models,
                $input['as_of'],
                $input['connection'],
                $input['as_of'],
                true,
            )
            : null;
        $dailyCheckoutByApparatus = $dailyCheckout === null
            ? []
            : collect($dailyCheckout['matrix'])->keyBy('apparatus_id')->all();
        $inspectionEvidence = $schema['apparatus_inspection_integrity_columns_present']
            ? $this->inspectionEvidence($connection, $apparatusIds, $startOfDay, $startOfNextDay, $input['as_of'])
            : [];
        $cutover = $schema['daily_checkout_ledger_cutover_present']
            ? $this->cutoverEvidence($connection, $input['candidate_sha'], $input['as_of'])
            : $this->missingCutoverEvidence('daily_checkout_ledger_cutover_schema_absent');
        $statusEvents = $schema['apparatus_operational_status_ledger_present']
            ? $this->statusEvents($connection, $apparatusIds, $input['as_of'], $cutover['activated_at'])
            : [];

        $rows = [];
        $technicalIssues = array_merge($schemaIssues, $cutover['issues']);
        $policyIssues = [];
        $seenPolicyIds = [];
        foreach ($apparatuses as $apparatus) {
            $apparatusId = (int) $apparatus->id;
            $policy = $manifest['apparatus'][$apparatusId] ?? null;
            if ($policy !== null) {
                $seenPolicyIds[$apparatusId] = true;
            }

            $rowIssues = [];
            $rowPolicyIssues = [];
            if ($policy === null) {
                $rowIssues[] = 'apparatus_present_but_not_in_approved_policy';
            } else {
                $rowIssues = array_merge($rowIssues, $this->policyMismatches($apparatus, $policy));
                if ($policy['operational_classification'] === 'unknown') {
                    $rowPolicyIssues[] = 'operational_classification_required';
                }
            }

            $model = $models->first(static fn (Apparatus $model): bool => (int) $model->id === $apparatusId);
            if (! $model instanceof Apparatus) {
                $rowIssues[] = 'apparatus_model_unavailable';
                $resolution = null;
            } else {
                $resolution = $checklists->resolve($model);
            }

            $checklist = $this->checklistRow($resolution, $checklistEvidence);
            $databaseRequirement = $this->normalizedString($apparatus->daily_checkout_requirement ?? null);
            if ($databaseRequirement === null || DailyCheckoutRequirement::tryFrom($databaseRequirement) === null) {
                $rowIssues[] = 'daily_checkout_requirement_invalid';
            } elseif ($databaseRequirement === DailyCheckoutRequirement::Unknown->value) {
                // Unknown is deliberately fail-closed by the canonical Daily
                // Checkout matrix. It stays visible and outside the required
                // completion denominator, but is an owner policy follow-up,
                // not a technical defect in the staged candidate.
                $rowPolicyIssues[] = 'classification_required';
            }

            $requiredChecklistVersion = null;
            if ($policy !== null && $policy['daily_checkout_requirement'] === DailyCheckoutRequirement::Required->value) {
                $requiredChecklistVersion = $policy['expected_checklist_version'];
                if (! $checklist['usable']) {
                    $rowIssues[] = 'required_apparatus_checklist_unusable';
                }
                if ($checklist['checklist_type'] !== $policy['expected_checklist_type']) {
                    $rowIssues[] = 'required_apparatus_checklist_type_mismatch';
                }
                if ($checklist['checklist_version'] !== $policy['expected_checklist_version']) {
                    $rowIssues[] = 'required_apparatus_checklist_version_mismatch';
                }
                if ($checklist['duplicate_identifiers'] !== []) {
                    $rowIssues[] = 'required_apparatus_checklist_duplicate_identifiers';
                }
            }

            $currentInspection = $inspectionEvidence[$apparatusId] ?? $this->emptyInspectionEvidence();
            $ledger = $this->ledgerEvidence(
                $apparatus,
                $statusEvents[$apparatusId] ?? [],
                $currentInspection['approved'],
                $policy['pre_ledger_oos_review'] ?? null,
                $startOfDay,
                $requiredChecklistVersion,
                $cutover['record'],
                array_key_exists($apparatusId, $cutover['apparatus_status_by_id']),
                $cutover['apparatus_status_by_id'][$apparatusId] ?? null,
            );
            $rowIssues = array_merge($rowIssues, $ledger['issues']);
            unset($ledger['issues']);

            $canonicalDaily = $dailyCheckoutByApparatus[$apparatusId] ?? null;
            if ($dailyCheckout !== null && $canonicalDaily === null) {
                $rowIssues[] = 'canonical_daily_state_unavailable';
            }
            if (
                $requiredChecklistVersion !== null
                && in_array($canonicalDaily['state'] ?? null, ['checked', 'attention'], true)
                && ($currentInspection['latest_approved']['checklist_version'] ?? null) !== $requiredChecklistVersion
            ) {
                $rowIssues[] = 'current_approved_checkout_checklist_version_mismatch';
            }

            $rowIssues = array_values(array_unique($rowIssues));
            $rowPolicyIssues = array_values(array_unique($rowPolicyIssues));
            $rowBlockingPolicyIssues = array_values(array_filter(
                $rowPolicyIssues,
                fn (string $issue): bool => ! self::isWaivedBetaPolicyIssue($input['allow_classification_required'], $issue),
            ));
            $rowBlockingIssues = array_values(array_unique([
                ...$rowIssues,
                ...$rowBlockingPolicyIssues,
            ]));
            $technicalIssues = array_merge($technicalIssues, $rowIssues);
            $policyIssues = array_merge($policyIssues, $rowPolicyIssues);
            $rows[] = [
                'apparatus_id' => $apparatusId,
                'unit_id' => $apparatus->unit_id,
                'designation' => $apparatus->designation,
                'name' => $apparatus->name,
                'vehicle_number' => $apparatus->vehicle_number,
                'type' => $apparatus->type,
                'station' => [
                    'id' => $apparatus->station_id === null ? null : (int) $apparatus->station_id,
                    'number' => $apparatus->station_number === null ? null : (string) $apparatus->station_number,
                    'name' => $apparatus->station_name,
                ],
                'operational_status' => $apparatus->status,
                'operational_classification' => $policy['operational_classification'] ?? null,
                'classification_required' => $databaseRequirement === DailyCheckoutRequirement::Unknown->value,
                'slug' => $apparatus->slug,
                'policy' => [
                    'manifest_requirement' => $policy['daily_checkout_requirement'] ?? null,
                    'database_requirement' => $databaseRequirement,
                    'manifest_template' => $policy['daily_checkout_template'] ?? null,
                    'database_template' => $this->normalizedString($apparatus->daily_checkout_template ?? null),
                ],
                'checklist' => $checklist,
                'current_inspection' => [
                    'latest' => $currentInspection['latest'],
                    'latest_approved' => $currentInspection['latest_approved'],
                    'pending_review_count' => $currentInspection['pending_review_count'],
                ],
                'canonical_daily' => $canonicalDaily,
                'oos_ledger' => $ledger,
                'technical_issues' => $rowIssues,
                'policy_issues' => $rowPolicyIssues,
                'blocking_policy_issues' => $rowBlockingPolicyIssues,
                'issues' => $rowBlockingIssues,
            ];
        }

        $missingExpected = [];
        foreach ($manifest['apparatus'] as $apparatusId => $policy) {
            if (! isset($seenPolicyIds[$apparatusId])) {
                $technicalIssues[] = 'expected_apparatus_missing';
                $missingExpected[] = [
                    'apparatus_id' => $apparatusId,
                    'unit_id' => $policy['unit_id'],
                    'designation' => $policy['designation'],
                ];
            }
        }

        $expectedAbsentPresent = [];
        foreach ($manifest['expected_absent'] as $expectedAbsent) {
            foreach ($apparatuses as $apparatus) {
                if ($this->matchesExpectedAbsent($apparatus, $expectedAbsent)) {
                    $technicalIssues[] = 'expected_absent_apparatus_present';
                    $expectedAbsentPresent[] = [
                        'apparatus_id' => (int) $apparatus->id,
                        'unit_id' => $apparatus->unit_id,
                        'designation' => $apparatus->designation,
                    ];
                }
            }
        }

        $technicalIssues = array_values(array_unique($technicalIssues));
        $policyIssues = array_values(array_unique($policyIssues));
        $blockingPolicyIssues = array_values(array_filter(
            $policyIssues,
            fn (string $issue): bool => ! self::isWaivedBetaPolicyIssue($input['allow_classification_required'], $issue),
        ));
        $blockingIssues = array_values(array_unique([
            ...$technicalIssues,
            ...$blockingPolicyIssues,
        ]));

        return [
            'schema_version' => 1,
            'generated_at_utc' => now('UTC')->toIso8601String(),
            'read_only' => true,
            'input' => [
                'connection' => $input['connection'],
                'snapshot_id' => $input['snapshot_id'],
                'as_of_utc' => $input['as_of']->toIso8601String(),
                'candidate_sha' => $input['candidate_sha'],
                'allow_classification_required' => $input['allow_classification_required'],
                'policy_manifest' => [
                    'schema_version' => $manifest['schema_version'],
                    'sha256' => $manifest['sha256'],
                    'candidate_sha' => $manifest['candidate_sha'],
                    // This is deliberately an evidence pointer, not a claim
                    // that the command cryptographically verified an owner.
                    'owner_approval' => [
                        'reference' => $manifest['approval_reference'],
                        'verification' => 'owner_supplied_out_of_band_not_cryptographically_verified',
                    ],
                ],
            ],
            'schema' => $schema,
            'daily_checkout_ledger_cutover' => $this->publicCutoverEvidence($cutover),
            'daily_checkout' => $dailyCheckout,
            'summary' => [
                'snapshot_apparatus_total' => count($apparatuses),
                'policy_apparatus_total' => count($manifest['apparatus']),
                'missing_expected_apparatus_total' => count($missingExpected),
                'expected_absent_present_total' => count($expectedAbsentPresent),
                'issues' => count($blockingIssues),
                'technical_issues' => count($technicalIssues),
                'policy_issues' => count($policyIssues),
                'blocking_policy_issues' => count($blockingPolicyIssues),
            ],
            'missing_expected_apparatus' => $missingExpected,
            'expected_absent_apparatus_present' => $expectedAbsentPresent,
            'issues' => $blockingIssues,
            'technical_issues' => $technicalIssues,
            'policy_issues' => $policyIssues,
            'blocking_policy_issues' => $blockingPolicyIssues,
            'apparatus' => $rows,
            'gate_passed' => $blockingIssues === [],
        ];
    }

    private static function isWaivedBetaPolicyIssue(bool $allowClassificationRequired, string $issue): bool
    {
        return $allowClassificationRequired && in_array($issue, [
            'classification_required',
            'operational_classification_required',
        ], true);
    }

    /** @return array<string, bool> */
    private function schema(string $connection): array
    {
        $schema = Schema::connection($connection);
        $inspectionColumns = [
            'apparatus_id',
            'client_submission_id',
            'submission_payload_hash',
            'checklist_version',
            'review_status',
            'completed_at',
        ];
        $cutoverColumns = [
            'ledger',
            'release_sha',
            'source',
            'activated_at',
            'apparatus_status_snapshot',
            'snapshot_sha256',
            'apparatus_count',
        ];

        return [
            'daily_checkout_requirement_column_present' => $schema->hasColumn('apparatuses', 'daily_checkout_requirement'),
            'daily_checkout_template_column_present' => $schema->hasColumn('apparatuses', 'daily_checkout_template'),
            'apparatus_operational_status_ledger_present' => $schema->hasTable('apparatus_operational_status_events'),
            'daily_checkout_ledger_cutover_present' => $schema->hasTable('daily_checkout_ledger_cutovers')
                && $this->hasColumns($schema, 'daily_checkout_ledger_cutovers', $cutoverColumns),
            'apparatus_inspection_integrity_columns_present' => $schema->hasTable('apparatus_inspections')
                && $this->hasColumns($schema, 'apparatus_inspections', $inspectionColumns),
        ];
    }

    /** @param list<string> $columns */
    private function hasColumns(object $schema, string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! $schema->hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, bool> $schema @return Collection<int, stdClass> */
    private function apparatuses(Connection $connection, array $schema): Collection
    {
        /** @var list<string|Expression> $columns */
        $columns = [
            'apparatuses.id',
            'apparatuses.station_id',
            'stations.station_number as station_number',
            'stations.name as station_name',
            'apparatuses.unit_id',
            'apparatuses.name',
            'apparatuses.vehicle_number',
            'apparatuses.type',
            'apparatuses.class_description',
            'apparatuses.designation',
            'apparatuses.slug',
            'apparatuses.status',
            $schema['daily_checkout_requirement_column_present']
                ? 'apparatuses.daily_checkout_requirement'
                : DB::raw('NULL as daily_checkout_requirement'),
            $schema['daily_checkout_template_column_present']
                ? 'apparatuses.daily_checkout_template'
                : DB::raw('NULL as daily_checkout_template'),
        ];

        /** @var Collection<int, stdClass> $apparatuses */
        $apparatuses = $connection->table('apparatuses')
            ->leftJoin('stations', 'stations.id', '=', 'apparatuses.station_id')
            ->select($columns)
            ->orderBy('apparatuses.id')
            ->get();

        return $apparatuses;
    }

    /**
     * @param  list<int>  $apparatusIds
     * @return array<int, array{latest: array<string, mixed>|null, latest_approved: array<string, mixed>|null, pending_review_count: int, approved: list<array{id: int, completed_at: CarbonImmutable, checklist_version: string|null}>}>
     */
    private function inspectionEvidence(
        Connection $connection,
        array $apparatusIds,
        CarbonImmutable $startOfDay,
        CarbonImmutable $startOfNextDay,
        CarbonImmutable $asOf,
    ): array {
        $evidence = [];
        foreach ($apparatusIds as $apparatusId) {
            $evidence[$apparatusId] = $this->emptyInspectionEvidence();
        }
        if ($apparatusIds === []) {
            return $evidence;
        }

        $inspections = $connection->table('apparatus_inspections')
            ->whereIn('apparatus_id', $apparatusIds)
            ->whereNotNull('client_submission_id')
            ->whereNotNull('submission_payload_hash')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $startOfDay)
            ->where('completed_at', '<', $startOfNextDay)
            ->where('completed_at', '<=', $asOf)
            ->orderBy('apparatus_id')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get([
                'id',
                'apparatus_id',
                'client_submission_id',
                'submission_payload_hash',
                'checklist_version',
                'review_status',
                'completed_at',
            ]);

        foreach ($inspections as $inspection) {
            $apparatusId = (int) $inspection->apparatus_id;
            $completedAt = $this->asUtc($inspection->completed_at);
            if ($completedAt === null) {
                continue;
            }

            $shape = [
                'id' => (int) $inspection->id,
                'review_status' => strtolower(trim((string) $inspection->review_status)),
                'completed_at_utc' => $completedAt->toIso8601String(),
                'checklist_version' => $this->normalizedString($inspection->checklist_version),
                'client_submission_id_present' => true,
                'submission_payload_hash_present' => true,
            ];
            $evidence[$apparatusId]['latest'] = $shape;
            if ($shape['review_status'] === 'pending_review') {
                $evidence[$apparatusId]['pending_review_count']++;
            }
            if ($shape['review_status'] === 'approved') {
                $evidence[$apparatusId]['approved'][] = [
                    'id' => (int) $inspection->id,
                    'completed_at' => $completedAt,
                    'checklist_version' => $this->normalizedString($inspection->checklist_version),
                ];
                $evidence[$apparatusId]['latest_approved'] = $shape;
            }
        }

        return $evidence;
    }

    /** @return array{latest: array<string, mixed>|null, latest_approved: array<string, mixed>|null, pending_review_count: int, approved: list<array{id: int, completed_at: CarbonImmutable, checklist_version: string|null}>} */
    private function emptyInspectionEvidence(): array
    {
        return [
            'latest' => null,
            'latest_approved' => null,
            'pending_review_count' => 0,
            'approved' => [],
        ];
    }

    /** @param list<int> $apparatusIds @return array<int, list<stdClass>> */
    private function statusEvents(
        Connection $connection,
        array $apparatusIds,
        CarbonImmutable $asOf,
        ?CarbonImmutable $cutoverActivatedAt,
    ): array {
        if ($apparatusIds === [] || $cutoverActivatedAt === null) {
            return [];
        }

        $events = $connection->table('apparatus_operational_status_events')
            ->whereIn('apparatus_id', $apparatusIds)
            ->where('changed_at', '>=', $cutoverActivatedAt)
            ->where('changed_at', '<=', $asOf)
            ->orderBy('apparatus_id')
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get(['id', 'apparatus_id', 'previous_status', 'status', 'changed_at']);

        $byApparatus = [];
        foreach ($events as $event) {
            $byApparatus[(int) $event->apparatus_id][] = $event;
        }

        return $byApparatus;
    }

    /**
     * @return array{
     *     record: array{ledger: string, release_sha: string, source: string, activated_at_utc: string, snapshot_sha256: string, apparatus_count: int}|null,
     *     activated_at: CarbonImmutable|null,
     *     apparatus_status_by_id: array<int, string|null>,
     *     issues: list<string>
     * }
     */
    private function cutoverEvidence(Connection $connection, ?string $candidateSha, CarbonImmutable $asOf): array
    {
        $row = $connection->table('daily_checkout_ledger_cutovers')
            ->where('ledger', DailyCheckoutLedgerCutover::LEDGER)
            ->first([
                'ledger',
                'release_sha',
                'source',
                'activated_at',
                'apparatus_status_snapshot',
                'snapshot_sha256',
                'apparatus_count',
            ]);
        if (! $row instanceof stdClass) {
            return $this->missingCutoverEvidence('daily_checkout_ledger_cutover_missing');
        }

        $issues = [];
        $activatedAt = $this->asUtc($row->activated_at);
        if ($activatedAt === null) {
            $issues[] = 'daily_checkout_ledger_cutover_timestamp_invalid';
        } elseif ($activatedAt->greaterThan($asOf)) {
            $issues[] = 'daily_checkout_ledger_cutover_after_snapshot';
        }
        $releaseSha = strtolower(trim((string) $row->release_sha));
        if (preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $releaseSha) !== 1) {
            $issues[] = 'daily_checkout_ledger_cutover_release_sha_invalid';
        } elseif ($candidateSha === null || ! hash_equals($candidateSha, $releaseSha)) {
            $issues[] = 'daily_checkout_ledger_cutover_candidate_sha_mismatch';
        }
        if ($row->ledger !== DailyCheckoutLedgerCutover::LEDGER || $row->source !== DailyCheckoutLedgerCutover::SOURCE) {
            $issues[] = 'daily_checkout_ledger_cutover_source_invalid';
        }

        $snapshot = $this->cutoverSnapshot($row->apparatus_status_snapshot);
        if ($snapshot === null || ! is_numeric($row->apparatus_count) || (int) $row->apparatus_count !== count($snapshot)) {
            $issues[] = 'daily_checkout_ledger_cutover_snapshot_invalid';
            $snapshot = [];
        } else {
            try {
                $encodedSnapshot = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $encodedSnapshot = false;
            }
            if (
                ! is_string($encodedSnapshot)
                || ! is_string($row->snapshot_sha256)
                || ! hash_equals($row->snapshot_sha256, hash('sha256', $encodedSnapshot))
            ) {
                $issues[] = 'daily_checkout_ledger_cutover_snapshot_invalid';
                $snapshot = [];
            }
        }

        $statusByApparatus = [];
        foreach ($snapshot as $entry) {
            $statusByApparatus[$entry['id']] = $entry['status'];
        }

        return [
            'record' => [
                'ledger' => (string) $row->ledger,
                'release_sha' => $releaseSha,
                'source' => (string) $row->source,
                'activated_at_utc' => $activatedAt?->toIso8601String() ?? '',
                'snapshot_sha256' => is_string($row->snapshot_sha256) ? $row->snapshot_sha256 : '',
                'apparatus_count' => is_numeric($row->apparatus_count) ? (int) $row->apparatus_count : 0,
            ],
            'activated_at' => $activatedAt,
            'apparatus_status_by_id' => $statusByApparatus,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * @return array{
     *     record: null,
     *     activated_at: null,
     *     apparatus_status_by_id: array<int, string|null>,
     *     issues: list<string>
     * }
     */
    private function missingCutoverEvidence(string $issue): array
    {
        return [
            'record' => null,
            'activated_at' => null,
            'apparatus_status_by_id' => [],
            'issues' => [$issue],
        ];
    }

    /** @return list<array{id: int, status: string|null}>|null */
    private function cutoverSnapshot(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $snapshot = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($snapshot) || ! array_is_list($snapshot)) {
            return null;
        }

        $normalized = [];
        $previousId = 0;
        foreach ($snapshot as $entry) {
            if (
                ! is_array($entry)
                || ! array_key_exists('id', $entry)
                || ! array_key_exists('status', $entry)
                || ! is_int($entry['id'])
                || $entry['id'] <= $previousId
                || (! is_string($entry['status']) && $entry['status'] !== null)
            ) {
                return null;
            }
            $previousId = $entry['id'];
            $normalized[] = [
                'id' => $entry['id'],
                'status' => $entry['status'],
            ];
        }

        return $normalized;
    }

    /**
     * @param  array{record: array{ledger: string, release_sha: string, source: string, activated_at_utc: string, snapshot_sha256: string, apparatus_count: int}|null, activated_at: CarbonImmutable|null, apparatus_status_by_id: array<int, string|null>, issues: list<string>}  $cutover
     * @return array<string, mixed>
     */
    private function publicCutoverEvidence(array $cutover): array
    {
        return [
            'present' => $cutover['record'] !== null,
            'record' => $cutover['record'],
            'issues' => $cutover['issues'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $resolution
     * @return array<string, mixed>
     */
    private function checklistRow(?array $resolution, DailyCheckoutChecklistEvidenceInspector $evidence): array
    {
        $diagnostics = $evidence->inspect($resolution['path'] ?? null);

        return [
            'configured_template' => $resolution['configured_template'] ?? null,
            'checklist_type' => $resolution['checklist_type'] ?? null,
            'resolution_source' => $resolution['resolution_source'] ?? null,
            'source_path' => $diagnostics['source_path'],
            'source_sha256' => $diagnostics['source_sha256'],
            'checklist_version' => $resolution['checklist_version'] ?? null,
            'item_count' => $diagnostics['item_count'] ?? ($resolution['item_count'] ?? null),
            'usable' => $resolution['usable'] ?? false,
            'error' => $resolution['error'] ?? 'resolution_unavailable',
            'duplicate_identifiers' => $diagnostics['duplicate_identifiers'],
        ];
    }

    /**
     * @param  list<stdClass>  $events
     * @param  list<array{id: int, completed_at: CarbonImmutable, checklist_version: string|null}>  $approvedInspections
     * @param  array<string, mixed>|null  $preLedgerReview
     * @param  array{ledger: string, release_sha: string, source: string, activated_at_utc: string, snapshot_sha256: string, apparatus_count: int}|null  $cutover
     * @return array<string, mixed>
     */
    private function ledgerEvidence(
        stdClass $apparatus,
        array $events,
        array $approvedInspections,
        ?array $preLedgerReview,
        CarbonImmutable $startOfDay,
        ?string $expectedChecklistVersion,
        ?array $cutover,
        bool $cutoverSnapshotContainsApparatus,
        ?string $observedStatusAtCutover,
    ): array {
        $latest = $this->latestEvent($events);
        $openOutOfService = null;
        $returnEvent = null;

        foreach ($events as $event) {
            $changedAt = $this->asUtc($event->changed_at);
            if ($changedAt === null) {
                continue;
            }
            if ($this->isOutOfService((string) $event->status)) {
                $openOutOfService = $event;

                continue;
            }
            if ($this->isOutOfService((string) $event->previous_status)) {
                $openOutOfService ??= $event;
            }
            if ($openOutOfService !== null && $this->isInService((string) $event->status)) {
                if ($changedAt->greaterThanOrEqualTo($startOfDay)) {
                    $returnEvent = $event;
                }
                $openOutOfService = null;
            }
        }
        $latestEvidence = $latest === null
            ? ['event' => null, 'matches_current_status' => null]
            : [
                'event' => $this->eventShape($latest),
                'matches_current_status' => $this->normalizedStatus($latest->status) === $this->normalizedStatus($apparatus->status),
            ];

        $return = null;
        if ($returnEvent !== null) {
            $returnAt = $this->asUtc($returnEvent->changed_at);
            if ($returnAt !== null) {
                $return = [
                    'source' => 'operational_status_ledger',
                    'event_id' => (int) $returnEvent->id,
                    'at_utc' => $returnAt->toIso8601String(),
                    'at' => $returnAt,
                ];
            }
        }
        if ($return === null && is_array($preLedgerReview) && $preLedgerReview['state'] === 'returned_to_service') {
            $returnAt = $this->asUtc($preLedgerReview['return_to_service_at_utc'] ?? null);
            if ($returnAt !== null) {
                $return = [
                    'source' => 'manual_pre_ledger_review',
                    'event_id' => null,
                    'at_utc' => $returnAt->toIso8601String(),
                    'at' => $returnAt,
                ];
            }
        }

        $qualifying = null;
        $postReturnInspections = [];
        if ($return !== null) {
            foreach ($approvedInspections as $inspection) {
                if ($inspection['completed_at']->greaterThan($return['at'])) {
                    $postReturnInspections[] = $inspection;
                    if ($expectedChecklistVersion !== null && $inspection['checklist_version'] !== $expectedChecklistVersion) {
                        continue;
                    }

                    $qualifying = [
                        'id' => $inspection['id'],
                        'completed_at_utc' => $inspection['completed_at']->toIso8601String(),
                        'checklist_version' => $inspection['checklist_version'],
                    ];
                }
            }
        }

        $issues = [];
        $operationalState = $this->operationalState((string) $apparatus->status);
        if ($operationalState === 'ambiguous') {
            $issues[] = 'operational_status_ambiguous';
        }
        if ($latestEvidence['matches_current_status'] === false) {
            $issues[] = 'operational_status_ledger_mismatch';
        }
        if ($preLedgerReview === null) {
            $issues[] = 'historical_oos_return_review_missing';
        } elseif ($preLedgerReview['state'] === 'unresolved') {
            $issues[] = 'historical_oos_return_ambiguous';
        } elseif (
            $preLedgerReview['state'] === 'not_applicable'
            && $return === null
            && $this->isInService((string) $apparatus->status)
        ) {
            $issues[] = 'historical_oos_return_review_required';
        }
        if ($return !== null && $qualifying === null) {
            $issues[] = $expectedChecklistVersion !== null && $postReturnInspections !== []
                ? 'post_return_checkout_checklist_version_mismatch'
                : 'post_return_checkout_missing_or_not_strictly_after_return';
        }

        $publicReturn = $return === null ? null : [
            'source' => $return['source'],
            'event_id' => $return['event_id'],
            'at_utc' => $return['at_utc'],
        ];

        return [
            'state' => $operationalState,
            'latest_event' => $latestEvidence['event'],
            'latest_event_matches_current_status' => $latestEvidence['matches_current_status'],
            'pre_ledger_history' => [
                'authority' => ($preLedgerReview['state'] ?? null) === 'history_unavailable'
                    ? 'unavailable'
                    : 'reviewed',
            ],
            'pre_ledger_review' => $preLedgerReview,
            'cutover' => $cutover === null ? null : [
                'ledger' => $cutover['ledger'],
                'release_sha' => $cutover['release_sha'],
                'source' => $cutover['source'],
                'activated_at_utc' => $cutover['activated_at_utc'],
                'snapshot_status_available' => $cutoverSnapshotContainsApparatus,
                'observed_operational_status' => $observedStatusAtCutover,
            ],
            'return_to_service' => $publicReturn,
            'qualifying_post_return_inspection' => $qualifying,
            'issues' => $issues,
        ];
    }

    /**
     * @param  list<stdClass>  $events
     */
    private function latestEvent(array $events): ?stdClass
    {
        $latest = null;
        foreach ($events as $event) {
            $latest = $event;
        }

        return $latest;
    }

    /** @return array{id: int, previous_status: string|null, status: string, changed_at_utc: string|null} */
    private function eventShape(stdClass $event): array
    {
        return [
            'id' => (int) $event->id,
            'previous_status' => $event->previous_status === null ? null : (string) $event->previous_status,
            'status' => (string) $event->status,
            'changed_at_utc' => $this->asUtc($event->changed_at)?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $policy @return list<string> */
    private function policyMismatches(stdClass $apparatus, array $policy): array
    {
        $issues = [];
        foreach (['unit_id', 'designation', 'name', 'vehicle_number', 'type', 'slug'] as $field) {
            if ($this->normalizedString($apparatus->{$field} ?? null) !== $policy[$field]) {
                $issues[] = "policy_data_mismatch:{$field}";
            }
        }
        $stationNumber = $apparatus->station_number === null ? null : (string) $apparatus->station_number;
        if ($stationNumber !== $policy['station_number']) {
            $issues[] = 'policy_data_mismatch:station_number';
        }
        if ($this->normalizedString($apparatus->daily_checkout_requirement ?? null) !== $policy['daily_checkout_requirement']) {
            $issues[] = 'daily_checkout_requirement_manifest_mismatch';
        }
        if ($this->normalizedString($apparatus->daily_checkout_template ?? null) !== $policy['daily_checkout_template']) {
            $issues[] = 'daily_checkout_template_manifest_mismatch';
        }

        return $issues;
    }

    /** @param array<string, string> $expectedAbsent */
    private function matchesExpectedAbsent(stdClass $apparatus, array $expectedAbsent): bool
    {
        $unitMatches = $expectedAbsent['unit_id'] === '' || $this->normalizedString($apparatus->unit_id ?? null) === $expectedAbsent['unit_id'];
        $designationMatches = $expectedAbsent['designation'] === '' || $this->normalizedString($apparatus->designation ?? null) === $expectedAbsent['designation'];

        return $unitMatches && $designationMatches;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function localDayWindow(CarbonImmutable $asOf): array
    {
        $startOfDay = $asOf->setTimezone(DailyCheckoutComplianceService::TIMEZONE)->startOfDay();

        return [$startOfDay->utc(), $startOfDay->addDay()->utc()];
    }

    private function asUtc(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value->utc();
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizedStatus(mixed $value): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim((string) $value)));
    }

    private function isOutOfService(string $status): bool
    {
        return in_array($this->normalizedStatus($status), ['out_of_service', 'oos', 'down', 'retired'], true);
    }

    private function isInService(string $status): bool
    {
        return in_array($this->normalizedStatus($status), ['in_service', 'active', 'available', 'ready'], true);
    }

    private function operationalState(string $status): string
    {
        if ($this->isOutOfService($status)) {
            return 'out_of_service';
        }
        if ($this->isInService($status)) {
            return 'in_service';
        }

        return 'ambiguous';
    }

    private function normalizedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function optionString(string $option): ?string
    {
        $value = $this->option($option);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array{connection: string, policy_manifest: string, snapshot_id: string, as_of: CarbonImmutable, candidate_sha: string|null, allow_classification_required: bool, issues: list<string>}  $input
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    private function blockedReport(array $input, array $issues): array
    {
        return [
            'schema_version' => 1,
            'generated_at_utc' => now('UTC')->toIso8601String(),
            'read_only' => true,
            'input' => [
                'connection' => $input['connection'],
                'snapshot_id' => $input['snapshot_id'],
                'as_of_utc' => $input['as_of']->toIso8601String(),
                'candidate_sha' => $input['candidate_sha'],
                'allow_classification_required' => $input['allow_classification_required'],
            ],
            'schema' => null,
            'daily_checkout' => null,
            'summary' => null,
            'issues' => array_values(array_unique($issues)),
            'technical_issues' => array_values(array_unique($issues)),
            'policy_issues' => [],
            'blocking_policy_issues' => [],
            'apparatus' => [],
            'gate_passed' => false,
        ];
    }

    /** @param array<string, mixed> $report */
    private function emit(array $report): int
    {
        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (JsonException) {
                $this->error('Could not encode the Daily Checkout preactivation report.');

                return self::FAILURE;
            }
        } else {
            $this->line('Daily Checkout preactivation gate: '.($report['gate_passed'] ? 'PASSED' : 'BLOCKED'));
            foreach ($report['issues'] as $issue) {
                $this->warn((string) $issue);
            }
            foreach (array_diff($report['policy_issues'], $report['issues']) as $issue) {
                $this->warn('Non-blocking policy hold: '.(string) $issue);
            }
        }

        return $report['gate_passed'] ? self::SUCCESS : self::FAILURE;
    }
}
