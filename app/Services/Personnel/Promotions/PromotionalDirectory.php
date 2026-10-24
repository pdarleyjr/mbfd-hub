<?php

declare(strict_types=1);

namespace App\Services\Personnel\Promotions;

use App\Models\Employee;
use App\Models\PromotionalList;
use App\Models\PromotionalListEntry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Promotional list fields for the Master Personnel Directory.
 *
 * Only lists in effect count: active, not deleted and not past their expiry date. Sorting and
 * filtering use correlated sub-selects on the directory query. Display values come from one
 * extra query per request, so a page of members never costs a query per row.
 *
 * An employee on more than one list in effect is shown on each of them, newest effective date first.
 */
final class PromotionalDirectory
{
    public const FIELDS = [
        'promotional_list' => 'Promotional list',
        'promotional_rank' => 'Promotional rank',
        'promotional_status' => 'Promotional status',
        'promotional_expires' => 'Promotional list expires',
    ];

    /** @var array<int, list<array{type: string, title: string, rank: int|null, status: string, expires: string|null}>>|null */
    private ?array $byEmployee = null;

    /** Sort key sub-select for one field: the best (lowest or alphabetical first) value across lists in effect. */
    public static function sortSubquery(string $key): Builder
    {
        $query = self::inEffectEntries()->whereColumn('pe.employee_id', 'employees.id');

        return match ($key) {
            'promotional_list' => $query->selectRaw('MIN(pl.title)'),
            'promotional_rank' => $query->where('pe.status', PromotionalListEntry::STATUS_ELIGIBLE)->selectRaw('MIN(pe.current_rank)'),
            'promotional_status' => $query->selectRaw('MIN(pe.status)'),
            'promotional_expires' => $query->selectRaw('MIN(pl.expires_on)'),
            default => throw new \InvalidArgumentException('Unsupported promotional field.'),
        };
    }

    /**
     * Employees holding an entry on a list in effect.
     *
     * @param  list<string>  $listTypes  restrict to these list types; empty means any
     * @param  bool  $eligibleOnly  only entries that currently hold a rank
     */
    public static function employeeIds(array $listTypes = [], bool $eligibleOnly = false): Builder
    {
        $query = self::inEffectEntries()->whereNotNull('pe.employee_id')->select('pe.employee_id');
        if ($listTypes !== []) {
            $query->whereIn('pl.list_type', $listTypes);
        }
        if ($eligibleOnly) {
            $query->where('pe.status', PromotionalListEntry::STATUS_ELIGIBLE);
        }

        return $query;
    }

    /** @return list<string> display lines, one per list in effect */
    public function lines(Employee $employee, string $key): array
    {
        $entries = $this->entries()[$employee->getKey()] ?? [];
        $prefix = count($entries) > 1;
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = match ($key) {
                'promotional_list' => $entry['type'].' - '.$entry['title'],
                'promotional_rank' => ($prefix ? $entry['type'].': ' : '').($entry['rank'] !== null ? (string) $entry['rank'] : 'Not ranked'),
                'promotional_status' => ($prefix ? $entry['type'].': ' : '').(PromotionalListEntry::statuses()[$entry['status']] ?? ucfirst($entry['status'])),
                'promotional_expires' => ($prefix ? $entry['type'].': ' : '').($entry['expires'] !== null ? Carbon::parse($entry['expires'])->format('M j, Y') : 'No expiry date'),
                default => throw new \InvalidArgumentException('Unsupported promotional field.'),
            };
        }

        return $lines;
    }

    /** @return array<int, list<array{type: string, title: string, rank: int|null, status: string, expires: string|null}>> */
    private function entries(): array
    {
        if ($this->byEmployee !== null) {
            return $this->byEmployee;
        }
        $this->byEmployee = [];
        $rows = self::inEffectEntries()->whereNotNull('pe.employee_id')
            ->orderByDesc('pl.effective_date')->orderBy('pl.id')
            ->get(['pe.employee_id', 'pe.current_rank', 'pe.status', 'pl.list_type', 'pl.type_label', 'pl.title', 'pl.expires_on']);
        foreach ($rows as $row) {
            $type = $row->list_type === PromotionalList::TYPE_OTHER && filled($row->type_label)
                ? (string) $row->type_label : (PromotionalList::types()[$row->list_type] ?? ucfirst(str_replace('_', ' ', (string) $row->list_type)));
            $this->byEmployee[(int) $row->employee_id][] = [
                'type' => $type, 'title' => (string) $row->title, 'rank' => $row->current_rank === null ? null : (int) $row->current_rank,
                'status' => (string) $row->status, 'expires' => $row->expires_on === null ? null : substr((string) $row->expires_on, 0, 10),
            ];
        }

        return $this->byEmployee;
    }

    private static function inEffectEntries(): Builder
    {
        $today = PromotionalList::today();

        return DB::table('promotional_list_entries as pe')
            ->join('promotional_lists as pl', 'pl.id', '=', 'pe.list_id')
            ->whereNull('pl.deleted_at')
            ->where('pl.status', PromotionalList::STATUS_ACTIVE)
            ->where(fn (Builder $date) => $date->whereNull('pl.expires_on')->orWhere('pl.expires_on', '>=', $today));
    }
}
