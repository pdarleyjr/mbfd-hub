<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusCall;
use App\Models\UnitStatus\UnitStatusCallUnit;
use App\Models\UnitStatus\UnitStatusRun;
use App\Support\UnitStatus\UnitRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Applies one Command call row to the Hub tables. Every step is an upsert or a guarded insert, so applying the same
 * row twice (overlap re-reads, a restart, an overlapping page) changes nothing the second time.
 */
final class CallApplier
{
    public function __construct(
        private readonly UnitRegistry $registry,
        private readonly RunLedger $ledger,
    ) {}

    /** @return bool Whether this row gave a provisional call its real PulsePoint id */
    public function apply(SourceCall $source, SyncContext $context): bool
    {
        return DB::transaction(function () use ($source, $context): bool {
            $existing = UnitStatusCall::query()->find($source->id);
            // A row older than what the Hub holds is a stale re-read; the newer one already did the work.
            if ($existing !== null && $source->updatedAt->lessThan($existing->source_updated_at)) {
                return false;
            }
            $idResolved = $existing !== null && $existing->external_id === null && $source->externalId !== null;

            $call = $this->store($source, $existing, $context);
            $this->applyUnits($source, $call, $existing === null, $context);

            if ($source->duplicateOf !== null) {
                $this->ledger->voidCall($call->source_call_id, UnitStatusRun::VOID_DUPLICATE, $context->now);
            }
            if ($source->externalId !== null && $source->duplicateOf === null) {
                $this->ledger->retireOlderCopies($source->externalId, $context->now);
            }

            return $idResolved;
        });
    }

    private function store(SourceCall $source, ?UnitStatusCall $existing, SyncContext $context): UnitStatusCall
    {
        $attributes = [
            'external_id' => $source->externalId,
            'duplicate_of' => $source->duplicateOf,
            'call_type' => $source->callType,
            'address' => $source->address,
            'lat' => $source->lat,
            'lng' => $source->lng,
            'location_quality' => $source->locationQuality,
            'received_at' => $source->receivedAt,
            'section' => $source->section,
            'source_status' => $source->sourceStatus,
            'closed_at' => $source->closedAt,
            'disappeared_at' => $source->disappearedAt,
            'first_seen_at' => $source->firstSeenAt,
            'last_seen_at' => $source->lastSeenAt,
            'source_updated_at' => $source->updatedAt,
            'source_url' => $source->sourceUrl,
            'units_json' => $source->units,
            'synced_at' => $context->now,
        ];
        if ($existing !== null) {
            $existing->forceFill($attributes)->save();

            return $existing;
        }

        $call = new UnitStatusCall($attributes);
        $call->source_call_id = $source->id;
        $call->created_at = $context->now;
        $call->save();

        return $call;
    }

    private function applyUnits(SourceCall $source, UnitStatusCall $call, bool $callIsNew, SyncContext $context): void
    {
        foreach ($this->ourUnits($source) as $code => $seen) {
            $code = (string) $code; // PHP turns the array key "300" into the integer 300
            $row = UnitStatusCallUnit::query()->where('source_call_id', $call->source_call_id)->where('unit_code', $code)->first();
            $unitIsNew = $row === null;
            $row ??= new UnitStatusCallUnit(['source_call_id' => $call->source_call_id, 'unit_code' => $code]);
            $row->fill([
                'designation' => $seen['designation'],
                'status' => $seen['status'],
                'confirmed' => $seen['confirmed'] || $row->confirmed,
                'first_seen_on_call_at' => $row->first_seen_on_call_at ?? ($callIsNew ? $source->firstSeenAt : $source->lastSeenAt),
                'status_observed_at' => $source->lastSeenAt,
            ])->save();

            $this->ledger->credit($source, $call, $code, $row->confirmed, $unitIsNew, $callIsNew, $context);
        }
    }

    /**
     * Our units on the call, one entry per unit however many times PulsePoint lists it.
     *
     * @return array<string, array{designation: string, status: string, confirmed: bool}>
     */
    private function ourUnits(SourceCall $source): array
    {
        $found = [];
        foreach ($source->units as $listed) {
            $match = $this->registry->matchDesignation($listed['unitId']);
            if ($match === null) {
                continue;
            }
            $code = $match->unit->code;
            $previous = $found[$code] ?? null;
            $found[$code] = [
                'designation' => $previous === null || $match->confirmed ? $listed['unitId'] : $previous['designation'],
                'status' => $listed['status'] !== '' || $previous === null ? $listed['status'] : $previous['status'],
                'confirmed' => $match->confirmed || ($previous['confirmed'] ?? false),
            ];
        }

        return $found;
    }
}
