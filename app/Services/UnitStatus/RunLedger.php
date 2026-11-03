<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusCall;
use App\Models\UnitStatus\UnitStatusRun;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The credit ledger (contract section 4). A run is created at most once per (unit, call), never moves, and is never
 * deleted: a credit that must stop counting is voided with a reason. Everything here is idempotent, so a delta read
 * twice, overlapping pages and a restart all leave the same rows.
 */
final class RunLedger
{
    public function __construct(private readonly DutyCalendar $calendar) {}

    /**
     * Credit a unit that this delivery shows on a call, if it is not credited yet and may be.
     *
     * @param  bool  $unitIsNew  The Hub has not seen this unit on this call before
     * @param  bool  $callIsNew  The Hub has not seen this call before
     */
    public function credit(SourceCall $source, UnitStatusCall $call, string $unitCode, bool $confirmed, bool $unitIsNew, bool $callIsNew, SyncContext $context): void
    {
        $existing = UnitStatusRun::query()->where('unit_code', $unitCode)->where('source_call_id', $call->source_call_id)->first();
        if ($existing !== null) {
            $this->confirm($existing, $confirmed, $call);

            return;
        }
        // A unit the Hub already knew on this call that has no run was skipped on purpose (before tracking began, a
        // duplicate): later updates never earn it a credit.
        if (! $unitIsNew || $source->duplicateOf !== null || $call->merged_at !== null || $call->duplicate_of !== null) {
            return;
        }

        [$attributedAt, $basis] = $this->attribution($source, $callIsNew);
        if ($attributedAt->lessThan($context->trackingStartsAt)) {
            return;
        }

        try {
            UnitStatusRun::query()->create([
                'unit_code' => $unitCode,
                'source_call_id' => $call->source_call_id,
                'external_id' => $call->external_id,
                'attributed_at' => $attributedAt,
                'attribution_basis' => $basis,
                'duty_date' => $this->calendar->dutyDate($attributedAt)->format('Y-m-d'),
                'confirmed' => $confirmed,
                'provenance' => $attributedAt->lessThan($context->firstSyncedAt) ? UnitStatusRun::PROVENANCE_BACKFILL : UnitStatusRun::PROVENANCE_LIVE,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another worker credited the same (unit, call) first. The ledger already holds the one row it may have.
        }
    }

    /** Stop counting every credit on a call. Rows stay, so the call can never be credited again. */
    public function voidCall(string $sourceCallId, string $reason, CarbonImmutable $now): void
    {
        UnitStatusRun::query()
            ->where('source_call_id', $sourceCallId)
            ->whereNull('voided_at')
            ->update(['voided_at' => $now, 'void_reason' => $reason]);
    }

    /** A provisional copy Command folded into another call. */
    public function markMerged(UnitStatusCall $call, CarbonImmutable $now): void
    {
        if ($call->merged_at === null) {
            $call->forceFill(['merged_at' => $now])->save();
        }
        $this->voidCall($call->source_call_id, UnitStatusRun::VOID_MERGED, $now);
    }

    /** When two Hub rows carry one external id, every row but the newest-created is a merged copy. */
    public function retireOlderCopies(string $externalId, CarbonImmutable $now): void
    {
        $copies = UnitStatusCall::query()
            ->where('external_id', $externalId)
            ->whereNull('duplicate_of')
            ->orderBy('created_at')
            ->orderBy('first_seen_at')
            ->orderBy('source_call_id')
            ->get();
        foreach ($copies->slice(0, max(0, $copies->count() - 1)) as $older) {
            $this->markMerged($older, $now);
        }
    }

    /** @return array{0: CarbonImmutable, 1: string} */
    private function attribution(SourceCall $source, bool $callIsNew): array
    {
        if (! $callIsNew) {
            return [$source->lastSeenAt, UnitStatusRun::BASIS_UNIT_ADDED];
        }

        return $source->receivedAt !== null
            ? [$source->receivedAt, UnitStatusRun::BASIS_RECEIVED]
            : [$source->firstSeenAt, UnitStatusRun::BASIS_FIRST_SEEN];
    }

    /** Unconfirmed becomes confirmed once, and never goes back. */
    private function confirm(UnitStatusRun $run, bool $confirmed, UnitStatusCall $call): void
    {
        $changes = [];
        if ($confirmed && ! $run->confirmed) {
            $changes['confirmed'] = true;
        }
        if ($run->external_id === null && $call->external_id !== null) {
            $changes['external_id'] = $call->external_id;
        }
        if ($changes !== []) {
            $run->forceFill($changes)->save();
        }
    }
}
