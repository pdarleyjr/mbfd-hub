<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Enums\DailyCheckoutRequirement;
use Carbon\CarbonImmutable;
use JsonException;
use RuntimeException;

/**
 * Validates the owner-supplied, read-only policy input for a staged Daily
 * Checkout cutover. It deliberately never infers apparatus classifications.
 */
final class DailyCheckoutPreactivationManifest
{
    public const SCHEMA_VERSION = 1;

    /**
     * @return array{
     *     schema_version: int,
     *     snapshot_id: string,
     *     as_of_utc: CarbonImmutable,
     *     approval_reference: string,
     *     candidate_sha: string,
     *     sha256: string,
     *     apparatus: array<int, array<string, mixed>>,
     *     expected_absent: list<array<string, string>>
     * }
     */
    public function load(
        string $path,
        string $expectedSnapshotId,
        CarbonImmutable $expectedAsOf,
        string $expectedCandidateSha,
    ): array {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('policy_manifest_unreadable');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('policy_manifest_unreadable');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('policy_manifest_invalid_json');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('policy_manifest_invalid_root');
        }

        if (($decoded['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new RuntimeException('policy_manifest_schema_version_invalid');
        }

        $snapshotId = $this->requiredString($decoded, 'snapshot_id');
        if ($snapshotId !== $expectedSnapshotId) {
            throw new RuntimeException('policy_manifest_snapshot_id_mismatch');
        }

        $asOf = $this->parseTimestamp($this->requiredString($decoded, 'as_of_utc'), 'policy_manifest_as_of_invalid');
        if (! $asOf->equalTo($expectedAsOf)) {
            throw new RuntimeException('policy_manifest_as_of_mismatch');
        }

        $candidateSha = $this->immutableSha(
            $this->requiredString($decoded, 'candidate_sha'),
            'policy_manifest_candidate_sha_invalid',
        );
        if (! hash_equals($expectedCandidateSha, $candidateSha)) {
            throw new RuntimeException('policy_manifest_candidate_sha_mismatch');
        }

        $approval = $decoded['approval'] ?? null;
        if (! is_array($approval)) {
            throw new RuntimeException('policy_manifest_approval_missing');
        }
        $approvalReference = $this->requiredString($approval, 'reference', 'policy_manifest_approval_reference_missing');

        $apparatus = $decoded['apparatus'] ?? null;
        if (! is_array($apparatus) || ! array_is_list($apparatus) || $apparatus === []) {
            throw new RuntimeException('policy_manifest_apparatus_missing');
        }

        $byId = [];
        foreach ($apparatus as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('policy_manifest_apparatus_row_invalid');
            }

            $normalized = $this->normalizeApparatus($row);
            $id = $normalized['id'];
            if (isset($byId[$id])) {
                throw new RuntimeException('policy_manifest_apparatus_id_duplicate');
            }
            $byId[$id] = $normalized;
        }

        if (! array_key_exists('expected_absent', $decoded)) {
            throw new RuntimeException('policy_manifest_expected_absent_missing');
        }
        $expectedAbsent = $decoded['expected_absent'];
        if (! is_array($expectedAbsent) || ! array_is_list($expectedAbsent)) {
            throw new RuntimeException('policy_manifest_expected_absent_invalid');
        }

        $normalizedExpectedAbsent = [];
        foreach ($expectedAbsent as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('policy_manifest_expected_absent_row_invalid');
            }

            $unitId = $this->nullableString($row, 'unit_id');
            $designation = $this->nullableString($row, 'designation');
            if ($unitId === null && $designation === null) {
                throw new RuntimeException('policy_manifest_expected_absent_identity_missing');
            }
            if (($row['disposition'] ?? null) !== 'intentionally_absent') {
                throw new RuntimeException('policy_manifest_expected_absent_disposition_invalid');
            }

            $normalizedExpectedAbsent[] = [
                'unit_id' => $unitId ?? '',
                'designation' => $designation ?? '',
                'disposition' => 'intentionally_absent',
                'evidence_reference' => $this->requiredString($row, 'evidence_reference', 'policy_manifest_expected_absent_evidence_missing'),
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'snapshot_id' => $snapshotId,
            'as_of_utc' => $asOf,
            'approval_reference' => $approvalReference,
            'candidate_sha' => $candidateSha,
            'sha256' => hash('sha256', $contents),
            'apparatus' => $byId,
            'expected_absent' => $normalizedExpectedAbsent,
        ];
    }

    /** @param array<string, mixed> $row */
    private function normalizeApparatus(array $row): array
    {
        $id = $row['id'] ?? null;
        if (! is_int($id) || $id <= 0) {
            throw new RuntimeException('policy_manifest_apparatus_id_invalid');
        }

        $requirement = $this->requiredString($row, 'daily_checkout_requirement');
        if (DailyCheckoutRequirement::tryFrom($requirement) === null) {
            throw new RuntimeException('policy_manifest_daily_requirement_invalid');
        }

        $template = $this->requiredString($row, 'daily_checkout_template');
        if (DailyCheckoutChecklistTemplate::tryFrom($template) === null) {
            throw new RuntimeException('policy_manifest_daily_template_invalid');
        }

        $expectedChecklistType = $this->nullableString($row, 'expected_checklist_type');
        if ($expectedChecklistType !== null) {
            $checklistTemplate = DailyCheckoutChecklistTemplate::tryFrom($expectedChecklistType);
            if ($checklistTemplate === null || ! $checklistTemplate->isConfigured()) {
                throw new RuntimeException('policy_manifest_expected_checklist_type_invalid');
            }
        }

        $expectedChecklistVersion = $this->nullableString($row, 'expected_checklist_version');
        if ($expectedChecklistVersion !== null && preg_match('/^[a-f0-9]{64}$/', $expectedChecklistVersion) !== 1) {
            throw new RuntimeException('policy_manifest_expected_checklist_version_invalid');
        }

        if ($requirement === DailyCheckoutRequirement::Required->value && ($expectedChecklistType === null || $expectedChecklistVersion === null)) {
            throw new RuntimeException('policy_manifest_required_checklist_evidence_missing');
        }

        $operationalClassification = $this->requiredString($row, 'operational_classification');
        if (! in_array($operationalClassification, ['active', 'reserve', 'inactive', 'unknown'], true)) {
            throw new RuntimeException('policy_manifest_operational_classification_invalid');
        }

        $review = $row['pre_ledger_oos_review'] ?? null;
        if (! is_array($review)) {
            throw new RuntimeException('policy_manifest_pre_ledger_oos_review_missing');
        }
        $reviewState = $this->requiredString($review, 'state', 'policy_manifest_pre_ledger_oos_review_state_invalid');
        if (! in_array($reviewState, ['no_same_day_return', 'returned_to_service', 'not_applicable', 'unresolved'], true)) {
            throw new RuntimeException('policy_manifest_pre_ledger_oos_review_state_invalid');
        }
        $reviewReturnAt = $this->nullableString($review, 'return_to_service_at_utc');
        if ($reviewState === 'returned_to_service') {
            if ($reviewReturnAt === null) {
                throw new RuntimeException('policy_manifest_pre_ledger_return_timestamp_missing');
            }
            $reviewReturnAt = $this->parseTimestamp($reviewReturnAt, 'policy_manifest_pre_ledger_return_timestamp_invalid')->toIso8601String();
        } elseif ($reviewReturnAt !== null) {
            throw new RuntimeException('policy_manifest_pre_ledger_return_timestamp_unexpected');
        }

        $evidenceReference = $this->nullableString($review, 'evidence_reference');
        if (in_array($reviewState, ['no_same_day_return', 'returned_to_service'], true) && $evidenceReference === null) {
            throw new RuntimeException('policy_manifest_pre_ledger_oos_evidence_missing');
        }

        return [
            'id' => $id,
            'unit_id' => $this->nullableString($row, 'unit_id'),
            'designation' => $this->nullableString($row, 'designation'),
            'name' => $this->nullableString($row, 'name'),
            'vehicle_number' => $this->nullableString($row, 'vehicle_number'),
            'type' => $this->nullableString($row, 'type'),
            'station_number' => $this->nullableString($row, 'station_number'),
            'operational_classification' => $operationalClassification,
            'daily_checkout_requirement' => $requirement,
            'daily_checkout_template' => $template,
            'expected_checklist_type' => $expectedChecklistType,
            'expected_checklist_version' => $expectedChecklistVersion,
            'slug' => $this->nullableString($row, 'slug'),
            'pre_ledger_oos_review' => [
                'state' => $reviewState,
                'return_to_service_at_utc' => $reviewReturnAt,
                'evidence_reference' => $evidenceReference,
            ],
        ];
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key, string $error = 'policy_manifest_required_value_missing'): string
    {
        $value = $this->nullableString($values, $key);
        if ($value === null) {
            throw new RuntimeException($error);
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function nullableString(array $values, string $key): ?string
    {
        if (! array_key_exists($key, $values) || $values[$key] === null) {
            return null;
        }
        if (! is_string($values[$key])) {
            throw new RuntimeException('policy_manifest_value_type_invalid');
        }

        $value = trim($values[$key]);

        return $value === '' ? null : $value;
    }

    private function parseTimestamp(string $value, string $error): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            throw new RuntimeException($error);
        }
    }

    private function immutableSha(string $value, string $error): string
    {
        $value = strtolower($value);
        if (preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $value) !== 1) {
            throw new RuntimeException($error);
        }

        return $value;
    }
}
