<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusCall;
use App\Models\UnitStatus\UnitStatusCallUnit;
use App\Models\UnitStatus\UnitStatusRun;
use App\Support\UnitStatus\StatusFamily;
use App\Support\UnitStatus\UnitDefinition;
use App\Support\UnitStatus\UnitStatusTime;

/** The recent run list behind a unit profile: newest first, voided credits left out, nothing but call facts. */
final class RunHistory
{
    public const PER_PAGE = 10;

    /** @return array{unit: array{code: string, name: string}, runs: list<array<string, mixed>>, page: int, hasMore: bool} */
    public function page(UnitDefinition $unit, int $page): array
    {
        $page = max(1, min($page, 1000));
        $rows = UnitStatusRun::query()
            ->where('unit_code', $unit->code)
            ->whereNull('voided_at')
            // Unconfirmed credits are never shown (contract 10.1).
            ->where('confirmed', true)
            ->orderByDesc('attributed_at')
            ->orderByDesc('id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get();
        $hasMore = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE);

        $ids = $rows->pluck('source_call_id')->all();
        $calls = [];
        foreach (UnitStatusCall::query()->whereIn('source_call_id', $ids)->get() as $stored) {
            $calls[$stored->source_call_id] = $stored;
        }
        $statuses = UnitStatusCallUnit::query()->whereIn('source_call_id', $ids)->where('unit_code', $unit->code)->pluck('status', 'source_call_id');

        $runs = [];
        foreach ($rows as $run) {
            $externalId = $run->external_id;
            $callType = '';
            if (isset($calls[$run->source_call_id])) {
                $externalId = $calls[$run->source_call_id]->external_id ?? $externalId;
                $callType = $calls[$run->source_call_id]->call_type;
            }
            $runs[] = [
                'attributedAt' => UnitStatusTime::iso($run->attributed_at),
                'attributedLabel' => UnitStatusTime::dateTime($run->attributed_at),
                'callType' => $callType,
                'idLabel' => $externalId !== null ? '#'.$externalId : 'ID pending',
                'externalId' => $externalId,
                'unitStatus' => StatusFamily::label((string) ($statuses->get($run->source_call_id) ?? '')),
                'provenance' => $run->provenance,
            ];
        }

        return ['unit' => ['code' => $unit->code, 'name' => $unit->name], 'runs' => $runs, 'page' => $page, 'hasMore' => $hasMore];
    }
}
