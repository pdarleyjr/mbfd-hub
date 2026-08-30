<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyCheckoutLedgerCutover;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use JsonException;
use stdClass;
use Throwable;

final class DailyCheckoutLedgerActivationEvidence
{
    /**
     * @return array{
     *     record: array{ledger: string, release_sha: string, source: string, activated_at_utc: string, snapshot_sha256: string, apparatus_count: int}|null,
     *     activated_at: CarbonImmutable|null,
     *     apparatus_status_by_id: array<int, string|null>,
     *     issues: list<string>
     * }
     */
    public function read(Connection $connection, CarbonImmutable $asOf): array
    {
        $schema = Schema::connection($connection->getName());
        if (
            ! $schema->hasTable('daily_checkout_ledger_cutovers')
            || ! $this->hasColumns($schema, [
                'ledger',
                'release_sha',
                'source',
                'activated_at',
                'apparatus_status_snapshot',
                'snapshot_sha256',
                'apparatus_count',
            ])
        ) {
            return $this->missing('daily_checkout_ledger_cutover_schema_absent');
        }

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
            return $this->missing('daily_checkout_ledger_cutover_missing');
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
        }
        if ($row->ledger !== DailyCheckoutLedgerCutover::LEDGER || $row->source !== DailyCheckoutLedgerCutover::SOURCE) {
            $issues[] = 'daily_checkout_ledger_cutover_source_invalid';
        }

        $snapshot = $this->snapshot($row->apparatus_status_snapshot);
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
                || ! hash_equals(strtolower($row->snapshot_sha256), hash('sha256', $encodedSnapshot))
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
                'snapshot_sha256' => is_string($row->snapshot_sha256) ? strtolower($row->snapshot_sha256) : '',
                'apparatus_count' => is_numeric($row->apparatus_count) ? (int) $row->apparatus_count : 0,
            ],
            'activated_at' => $activatedAt,
            'apparatus_status_by_id' => $statusByApparatus,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /** @param list<string> $columns */
    private function hasColumns(object $schema, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! $schema->hasColumn('daily_checkout_ledger_cutovers', $column)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{record: null, activated_at: null, apparatus_status_by_id: array<int, string|null>, issues: list<string>} */
    private function missing(string $issue): array
    {
        return [
            'record' => null,
            'activated_at' => null,
            'apparatus_status_by_id' => [],
            'issues' => [$issue],
        ];
    }

    /** @return list<array{id: int, status: string|null}>|null */
    private function snapshot(mixed $value): ?array
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

    private function asUtc(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
