<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Enums\DailyCheckoutRequirement;
use App\Models\Apparatus;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\DailyCheckoutLedgerActivationEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Produces the owner-beta policy manifest from a named, disposable candidate
 * database. It reads only objective database facts and never writes to it.
 */
final class GenerateDailyCheckoutPreactivationManifest extends Command
{
    protected $signature = 'daily-checkout:generate-preactivation-manifest
                            {--connection= : Named, disposable candidate database connection}
                            {--snapshot-id= : Immutable identifier for the staged snapshot}
                            {--as-of= : ISO-8601 assessment time for the snapshot}
                            {--candidate-sha= : Immutable candidate source SHA bound to the manifest}
                            {--candidate-image-digest= : Immutable candidate image digest bound to the manifest}
                            {--approval-reference= : Owner-beta approval evidence reference}
                            {--output= : New JSON file path for the generated manifest}
                            {--json : Emit the generation evidence as JSON}';

    protected $description = 'Generate a checksum-bound Daily Checkout beta manifest from a read-only candidate snapshot.';

    public function handle(
        DailyCheckoutChecklistResolver $checklists,
        DailyCheckoutLedgerActivationEvidence $activationEvidence,
    ): int {
        $input = $this->input();
        if ($input['issues'] !== []) {
            return $this->emit($this->blockedReport($input, $input['issues']));
        }

        try {
            $connection = DB::connection($input['connection']);
            $generation = $connection->transaction(function () use ($connection, $input, $checklists, $activationEvidence): array {
                if ($connection->getDriverName() === 'pgsql') {
                    $connection->statement('SET TRANSACTION READ ONLY');
                }

                return $this->generate($connection, $input, $checklists, $activationEvidence);
            });
        } catch (RuntimeException $exception) {
            return $this->emit($this->blockedReport($input, [$exception->getMessage()]));
        } catch (Throwable) {
            return $this->emit($this->blockedReport($input, ['manifest_generator_failed']));
        }

        if ($generation['issues'] !== []) {
            return $this->emit($this->blockedReport($input, $generation['issues'], $generation['source_evidence']));
        }

        try {
            $contents = json_encode($generation['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->emit($this->blockedReport($input, ['manifest_generator_json_encoding_failed'], $generation['source_evidence']));
        }
        if (! $this->writeNewFile($input['output'], $contents)) {
            return $this->emit($this->blockedReport($input, ['manifest_generator_output_write_failed'], $generation['source_evidence']));
        }

        return $this->emit([
            'schema_version' => 1,
            'state' => 'generated',
            'connection' => $input['connection'],
            'candidate_sha' => $input['candidate_sha'],
            'candidate_image_digest' => $input['candidate_image_digest'],
            'snapshot_id' => $input['snapshot_id'],
            'as_of_utc' => $input['as_of']->toIso8601String(),
            'manifest_path' => $input['output'],
            'manifest_sha256' => hash('sha256', $contents),
            'source_evidence' => $generation['source_evidence'],
            'issues' => [],
        ]);
    }

    /**
     * @return array{
     *     connection: string,
     *     snapshot_id: string,
     *     as_of: CarbonImmutable,
     *     candidate_sha: string,
     *     candidate_image_digest: string,
     *     approval_reference: string,
     *     output: string,
     *     issues: list<string>
     * }
     */
    private function input(): array
    {
        $connection = $this->optionString('connection');
        $snapshotId = $this->optionString('snapshot-id');
        $asOfValue = $this->optionString('as-of');
        $candidateSha = $this->optionString('candidate-sha');
        $candidateImageDigest = $this->optionString('candidate-image-digest');
        $approvalReference = $this->optionString('approval-reference');
        $output = $this->optionString('output');
        $issues = [];

        if ($connection === null) {
            $issues[] = 'manifest_generator_input_missing:connection';
        } elseif (str_contains($connection, '::')) {
            $issues[] = 'manifest_generator_connection_alias_not_allowed';
        } elseif ($connection === (string) config('database.default')) {
            $issues[] = 'manifest_generator_connection_must_not_be_default';
        } else {
            $connectionConfig = config('database.connections.'.$connection);
            if (! is_array($connectionConfig)) {
                $issues[] = 'manifest_generator_connection_unconfigured';
            } else {
                if (($connectionConfig['daily_checkout_preactivation_candidate'] ?? false) !== true) {
                    $issues[] = 'manifest_generator_connection_not_authorized_candidate';
                }
                if (($connectionConfig['daily_checkout_preactivation_read_only'] ?? false) !== true) {
                    $issues[] = 'manifest_generator_connection_not_declared_read_only';
                }
            }
        }
        if ($snapshotId === null) {
            $issues[] = 'manifest_generator_input_missing:snapshot_id';
        }
        if ($candidateSha === null || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/i', $candidateSha) !== 1) {
            $issues[] = 'manifest_generator_input_invalid:candidate_sha';
        } else {
            $candidateSha = strtolower($candidateSha);
        }
        if ($candidateImageDigest === null || preg_match('/^sha256:[a-f0-9]{64}$/i', $candidateImageDigest) !== 1) {
            $issues[] = 'manifest_generator_input_invalid:candidate_image_digest';
        } else {
            $candidateImageDigest = strtolower($candidateImageDigest);
        }
        if ($approvalReference === null) {
            $issues[] = 'manifest_generator_input_missing:approval_reference';
        }
        if ($output === null) {
            $issues[] = 'manifest_generator_input_missing:output';
        } elseif (file_exists($output)) {
            $issues[] = 'manifest_generator_output_already_exists';
        } elseif (! is_dir(dirname($output)) || ! is_writable(dirname($output))) {
            $issues[] = 'manifest_generator_output_directory_unwritable';
        }

        $asOf = CarbonImmutable::now('UTC');
        if ($asOfValue === null) {
            $issues[] = 'manifest_generator_input_missing:as_of';
        } else {
            try {
                $asOf = CarbonImmutable::parse($asOfValue)->utc();
            } catch (Throwable) {
                $issues[] = 'manifest_generator_input_invalid:as_of';
            }
        }

        return [
            'connection' => $connection ?? '',
            'snapshot_id' => $snapshotId ?? '',
            'as_of' => $asOf,
            'candidate_sha' => $candidateSha ?? '',
            'candidate_image_digest' => $candidateImageDigest ?? '',
            'approval_reference' => $approvalReference ?? '',
            'output' => $output ?? '',
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * @param  array{connection: string, snapshot_id: string, as_of: CarbonImmutable, candidate_sha: string, candidate_image_digest: string, approval_reference: string, output: string, issues: list<string>}  $input
     * @return array{
     *     manifest: array<string, mixed>,
     *     source_evidence: array{apparatus_count: int, legacy_status_transition_count: int, status_snapshot_sha256: string}|null,
     *     issues: list<string>
     * }
     */
    private function generate(
        Connection $connection,
        array $input,
        DailyCheckoutChecklistResolver $checklists,
        DailyCheckoutLedgerActivationEvidence $activationEvidence,
    ): array {
        $schema = Schema::connection($input['connection']);
        if (
            ! $schema->hasTable('apparatuses')
            || ! $schema->hasTable('stations')
            || ! $schema->hasTable('apparatus_operational_status_events')
            || ! $this->hasColumns($schema, 'apparatuses', [
                'id',
                'station_id',
                'unit_id',
                'designation',
                'name',
                'vehicle_number',
                'type',
                'status',
                'daily_checkout_requirement',
                'daily_checkout_template',
            ])
            || ! $this->hasColumns($schema, 'stations', ['id', 'station_number'])
            || ! $this->hasColumns($schema, 'apparatus_operational_status_events', ['apparatus_id', 'changed_at'])
        ) {
            throw new RuntimeException('manifest_generator_required_schema_missing');
        }

        $legacyStatusTransitionCount = (int) $connection->table('apparatus_operational_status_events')->count();
        $activation = $activationEvidence->read($connection, $input['as_of']);
        if ($activation['record'] === null || $activation['issues'] !== []) {
            return [
                'manifest' => [],
                'source_evidence' => null,
                'issues' => $activation['issues'],
            ];
        }
        $rows = $connection->table('apparatuses')
            ->leftJoin('stations', 'stations.id', '=', 'apparatuses.station_id')
            ->orderBy('apparatuses.id')
            ->get([
                'apparatuses.id as apparatus_id',
                'apparatuses.unit_id',
                'apparatuses.designation',
                'apparatuses.name',
                'apparatuses.vehicle_number',
                'apparatuses.type',
                'apparatuses.class_description',
                'apparatuses.slug',
                'apparatuses.status',
                'apparatuses.daily_checkout_requirement',
                'apparatuses.daily_checkout_template',
                'stations.station_number',
            ]);

        $statusSnapshot = [];
        $manifestRows = [];
        $issues = [];
        foreach ($rows as $row) {
            if (! is_numeric($row->apparatus_id)) {
                $issues[] = 'manifest_generator_apparatus_identity_invalid';

                continue;
            }
            $apparatusId = (int) $row->apparatus_id;
            if ($apparatusId <= 0) {
                $issues[] = 'manifest_generator_apparatus_identity_invalid';

                continue;
            }
            $statusSnapshot[] = [
                'id' => $apparatusId,
                'status' => $this->nullableString($row->status),
            ];
            $manifestRow = $this->manifestRow($row, $apparatusId, $input['connection'], $checklists, $legacyStatusTransitionCount);
            if ($manifestRow === null) {
                $issues[] = "manifest_generator_apparatus_policy_invalid:{$apparatusId}";

                continue;
            }
            $manifestRows[] = $manifestRow;
        }
        if ($manifestRows === []) {
            $issues[] = 'manifest_generator_apparatus_missing';
        }
        if ($legacyStatusTransitionCount !== 0) {
            // Actual telemetry must be adjudicated per apparatus. Do not turn
            // it into a blanket history_unavailable claim.
            $issues[] = 'manifest_generator_legacy_status_events_require_review';
        }

        try {
            $encodedStatusSnapshot = json_encode($statusSnapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('manifest_generator_status_snapshot_encoding_failed');
        }

        $sourceEvidence = [
            'apparatus_count' => count($statusSnapshot),
            'legacy_status_transition_count' => $legacyStatusTransitionCount,
            'status_snapshot_sha256' => hash('sha256', $encodedStatusSnapshot),
        ];

        return [
            'manifest' => [
                'schema_version' => 2,
                'snapshot_id' => $input['snapshot_id'],
                'as_of_utc' => $input['as_of']->toIso8601String(),
                'release_candidate' => [
                    'source_sha' => $input['candidate_sha'],
                    'image_digest' => $input['candidate_image_digest'],
                ],
                'ledger_activation' => $activation['record'],
                'approval' => [
                    'reference' => $input['approval_reference'],
                ],
                'apparatus' => $manifestRows,
                'expected_absent' => [],
            ],
            'source_evidence' => $sourceEvidence,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /** @return array<string, mixed>|null */
    private function manifestRow(
        stdClass $row,
        int $apparatusId,
        string $connection,
        DailyCheckoutChecklistResolver $checklists,
        int $legacyStatusTransitionCount,
    ): ?array {
        $requirement = $this->enumValue($row->daily_checkout_requirement, DailyCheckoutRequirement::class);
        $template = $this->enumValue($row->daily_checkout_template, DailyCheckoutChecklistTemplate::class);
        if ($requirement === null || $template === null) {
            return null;
        }

        $expectedChecklistType = null;
        $expectedChecklistVersion = null;
        if ($requirement === DailyCheckoutRequirement::Required->value) {
            $apparatus = new Apparatus;
            $apparatus->setConnection($connection);
            $apparatus->setRawAttributes([
                'id' => $apparatusId,
                'unit_id' => $this->nullableString($row->unit_id),
                'designation' => $this->nullableString($row->designation),
                'name' => $this->nullableString($row->name),
                'type' => $this->nullableString($row->type),
                'class_description' => $this->nullableString($row->class_description),
                'daily_checkout_template' => $template,
            ], true);
            $resolution = $checklists->resolve($apparatus);
            if (! $resolution['usable'] || $resolution['checklist_version'] === null) {
                return null;
            }
            $expectedChecklistType = $resolution['checklist_type'];
            $expectedChecklistVersion = $resolution['checklist_version'];
        }

        return [
            'id' => $apparatusId,
            'unit_id' => $this->nullableString($row->unit_id),
            'designation' => $this->nullableString($row->designation),
            'name' => $this->nullableString($row->name),
            'vehicle_number' => $this->nullableString($row->vehicle_number),
            'type' => $this->nullableString($row->type),
            'station_number' => $this->nullableString($row->station_number),
            // No operational classification is stored on the source row.
            // Preserve that absence as an explicit beta policy hold.
            'operational_classification' => 'unknown',
            'daily_checkout_requirement' => $requirement,
            'daily_checkout_template' => $template,
            'expected_checklist_type' => $expectedChecklistType,
            'expected_checklist_version' => $expectedChecklistVersion,
            'slug' => $this->nullableString($row->slug),
            'pre_ledger_oos_review' => [
                'state' => 'history_unavailable',
                'return_to_service_at_utc' => null,
                'evidence_reference' => "legacy-apparatus-status-transition-count:{$legacyStatusTransitionCount}",
            ],
        ];
    }

    /** @param class-string<DailyCheckoutRequirement|DailyCheckoutChecklistTemplate> $enum */
    private function enumValue(mixed $value, string $enum): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        $case = $enum::tryFrom(strtolower($value));

        return $case?->value;
    }

    /** @param list<string> $columns */
    private function hasColumns(\Illuminate\Database\Schema\Builder $schema, string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! $schema->hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function optionString(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function writeNewFile(string $path, string $contents): bool
    {
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            return false;
        }

        try {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($stream, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    return false;
                }
                $offset += $written;
            }

            return fflush($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array{connection: string, snapshot_id: string, as_of: CarbonImmutable, candidate_sha: string, candidate_image_digest: string, approval_reference: string, output: string, issues: list<string>}  $input
     * @param  array{apparatus_count: int, legacy_status_transition_count: int, status_snapshot_sha256: string}|null  $sourceEvidence
     * @return array<string, mixed>
     */
    private function blockedReport(array $input, array $issues, ?array $sourceEvidence = null): array
    {
        return [
            'schema_version' => 1,
            'state' => 'blocked',
            'connection' => $input['connection'],
            'candidate_sha' => $input['candidate_sha'],
            'candidate_image_digest' => $input['candidate_image_digest'],
            'snapshot_id' => $input['snapshot_id'],
            'as_of_utc' => $input['as_of']->toIso8601String(),
            'manifest_path' => $input['output'],
            'manifest_sha256' => null,
            'source_evidence' => $sourceEvidence,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /** @param array<string, mixed> $report */
    private function emit(array $report): int
    {
        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (JsonException) {
                $this->error('Could not encode the Daily Checkout manifest generation result.');

                return self::FAILURE;
            }
        } else {
            $this->line('Daily Checkout manifest generation: '.strtoupper((string) $report['state']));
            foreach ($report['issues'] as $issue) {
                $this->warn((string) $issue);
            }
        }

        return $report['state'] === 'generated' ? self::SUCCESS : self::FAILURE;
    }
}
