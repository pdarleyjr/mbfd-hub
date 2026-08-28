<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Apparatus;
use App\Models\DailyCheckoutLedgerCutover;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Establishes the one-way Daily Checkout trust boundary. It snapshots current
 * apparatus status without creating synthetic operational-status transitions.
 */
final class ActivateDailyCheckoutLedger extends Command
{
    protected $signature = 'daily-checkout:activate-ledger
                            {--release-sha= : Exact deployed Git SHA bound to the first activation}
                            {--json : Emit the activation result as JSON}';

    protected $description = 'Establish the immutable Daily Checkout ledger activation boundary.';

    public function handle(): int
    {
        $releaseSha = $this->releaseSha();
        if ($releaseSha === null) {
            return $this->emit($this->blockedReport(null, ['daily_checkout_ledger_cutover_release_sha_invalid']));
        }
        if (! Schema::hasTable('daily_checkout_ledger_cutovers')) {
            return $this->emit($this->blockedReport($releaseSha, ['daily_checkout_ledger_cutover_schema_absent']));
        }

        try {
            $report = DB::transaction(fn (): array => $this->activate($releaseSha));
        } catch (QueryException) {
            // A concurrent first activation is safe only if the immutable
            // record was completed and is itself valid. Re-read after the
            // failed transaction rather than attempting an overwrite.
            $existing = DailyCheckoutLedgerCutover::query()
                ->where('ledger', DailyCheckoutLedgerCutover::LEDGER)
                ->first();
            if ($existing instanceof DailyCheckoutLedgerCutover && $this->cutoverIsIntact($existing)) {
                $report = $this->existingReport($releaseSha, $existing);
            } else {
                return $this->emit($this->blockedReport($releaseSha, ['daily_checkout_ledger_cutover_activation_failed']));
            }
        } catch (RuntimeException $exception) {
            return $this->emit($this->blockedReport($releaseSha, [$exception->getMessage()]));
        } catch (Throwable) {
            return $this->emit($this->blockedReport($releaseSha, ['daily_checkout_ledger_cutover_activation_failed']));
        }

        return $this->emit($report);
    }

    /** @return array<string, mixed> */
    private function activate(string $releaseSha): array
    {
        $existing = DailyCheckoutLedgerCutover::query()
            ->where('ledger', DailyCheckoutLedgerCutover::LEDGER)
            ->lockForUpdate()
            ->first();
        if ($existing instanceof DailyCheckoutLedgerCutover) {
            if (! $this->cutoverIsIntact($existing)) {
                throw new RuntimeException('daily_checkout_ledger_cutover_existing_invalid');
            }

            return $this->existingReport($releaseSha, $existing);
        }

        $apparatuses = Apparatus::query()
            ->lockForUpdate()
            ->orderBy('id')
            ->get(['id', 'status']);
        /** @var list<array{id: int, status: string|null}> $snapshot */
        $snapshot = [];
        foreach ($apparatuses as $apparatus) {
            $status = $apparatus->getAttribute('status');
            $snapshot[] = [
                'id' => (int) $apparatus->getAttribute('id'),
                'status' => is_string($status) ? $status : null,
            ];
        }
        $encodedSnapshot = $this->encodeSnapshot($snapshot);
        $activatedAt = CarbonImmutable::now('UTC');

        $cutover = DailyCheckoutLedgerCutover::query()->create([
            'ledger' => DailyCheckoutLedgerCutover::LEDGER,
            'release_sha' => $releaseSha,
            'source' => DailyCheckoutLedgerCutover::SOURCE,
            'activated_at' => $activatedAt,
            'apparatus_status_snapshot' => $snapshot,
            'snapshot_sha256' => hash('sha256', $encodedSnapshot),
            'apparatus_count' => count($snapshot),
        ]);

        return [
            'schema_version' => 1,
            'state' => 'activated',
            'requested_release_sha' => $releaseSha,
            'cutover' => $this->cutoverShape($cutover),
            'issues' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function existingReport(string $releaseSha, DailyCheckoutLedgerCutover $cutover): array
    {
        return [
            'schema_version' => 1,
            'state' => 'already_activated',
            'requested_release_sha' => $releaseSha,
            'cutover' => $this->cutoverShape($cutover),
            'issues' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function blockedReport(?string $releaseSha, array $issues): array
    {
        return [
            'schema_version' => 1,
            'state' => 'blocked',
            'requested_release_sha' => $releaseSha,
            'cutover' => null,
            'issues' => $issues,
        ];
    }

    /** @return array{ledger: string, release_sha: string, source: string, activated_at_utc: string, snapshot_sha256: string, apparatus_count: int} */
    private function cutoverShape(DailyCheckoutLedgerCutover $cutover): array
    {
        $data = $this->cutoverData($cutover);
        if ($data === null) {
            throw new RuntimeException('daily_checkout_ledger_cutover_existing_invalid');
        }

        return [
            'ledger' => $data['ledger'],
            'release_sha' => $data['release_sha'],
            'source' => $data['source'],
            'activated_at_utc' => $data['activated_at']->utc()->toIso8601String(),
            'snapshot_sha256' => $data['snapshot_sha256'],
            'apparatus_count' => $data['apparatus_count'],
        ];
    }

    private function cutoverIsIntact(DailyCheckoutLedgerCutover $cutover): bool
    {
        return $this->cutoverData($cutover) !== null;
    }

    /**
     * @return array{ledger: string, release_sha: string, source: string, activated_at: CarbonImmutable, apparatus_status_snapshot: list<array{id: int, status: string|null}>, snapshot_sha256: string, apparatus_count: int}|null
     */
    private function cutoverData(DailyCheckoutLedgerCutover $cutover): ?array
    {
        $ledger = $cutover->getAttribute('ledger');
        $releaseSha = $cutover->getAttribute('release_sha');
        $source = $cutover->getAttribute('source');
        $activatedAt = $cutover->getAttribute('activated_at');
        $snapshot = $this->snapshotData($cutover->getAttribute('apparatus_status_snapshot'));
        $snapshotSha256 = $cutover->getAttribute('snapshot_sha256');
        $apparatusCount = $cutover->getAttribute('apparatus_count');

        if (
            $ledger !== DailyCheckoutLedgerCutover::LEDGER
            || ! is_string($releaseSha)
            || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/i', $releaseSha) !== 1
            || $source !== DailyCheckoutLedgerCutover::SOURCE
            || ! $activatedAt instanceof CarbonImmutable
            || $snapshot === null
            || ! is_string($snapshotSha256)
            || preg_match('/^[a-f0-9]{64}$/i', $snapshotSha256) !== 1
            || ! is_int($apparatusCount)
            || $apparatusCount !== count($snapshot)
        ) {
            return null;
        }

        try {
            $encodedSnapshot = $this->encodeSnapshot($snapshot);
        } catch (RuntimeException) {
            return null;
        }

        if (! hash_equals($snapshotSha256, hash('sha256', $encodedSnapshot))) {
            return null;
        }

        return [
            'ledger' => $ledger,
            'release_sha' => $releaseSha,
            'source' => $source,
            'activated_at' => $activatedAt,
            'apparatus_status_snapshot' => $snapshot,
            'snapshot_sha256' => $snapshotSha256,
            'apparatus_count' => $apparatusCount,
        ];
    }

    /** @return list<array{id: int, status: string|null}>|null */
    private function snapshotData(mixed $snapshot): ?array
    {
        if (! is_array($snapshot) || ! array_is_list($snapshot)) {
            return null;
        }

        $normalized = [];
        $previousId = 0;
        foreach ($snapshot as $row) {
            if (
                ! is_array($row)
                || ! array_key_exists('id', $row)
                || ! array_key_exists('status', $row)
            ) {
                return null;
            }

            $id = $row['id'];
            $status = $row['status'];
            if (
                ! is_int($id)
                || $id <= $previousId
                || (! is_string($status) && $status !== null)
            ) {
                return null;
            }

            $normalized[] = [
                'id' => $id,
                'status' => $status,
            ];
            $previousId = $id;
        }

        return $normalized;
    }

    /** @param list<array{id: int, status: string|null}> $snapshot */
    private function encodeSnapshot(array $snapshot): string
    {
        try {
            return json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('daily_checkout_ledger_cutover_snapshot_invalid');
        }
    }

    private function releaseSha(): ?string
    {
        $releaseSha = $this->option('release-sha');
        if (! is_string($releaseSha)) {
            return null;
        }

        $releaseSha = strtolower(trim($releaseSha));

        return preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $releaseSha) === 1
            ? $releaseSha
            : null;
    }

    /** @param array<string, mixed> $report */
    private function emit(array $report): int
    {
        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (JsonException) {
                $this->error('Could not encode the Daily Checkout ledger cutover result.');

                return self::FAILURE;
            }
        } else {
            $this->line('Daily Checkout ledger cutover: '.($report['state'] === 'blocked' ? 'BLOCKED' : strtoupper((string) $report['state'])));
            foreach ($report['issues'] as $issue) {
                $this->warn((string) $issue);
            }
        }

        return $report['state'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
