<?php

declare(strict_types=1);

namespace App\Services\Ems\Kiosk;

use App\Models\Apparatus;
use App\Models\Employee;
use App\Models\Ems\KioskDevice;
use App\Models\Ems\KioskSearchTerm;
use App\Models\Ems\KioskWithdrawal;
use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyItem;
use App\Services\Ems\CountMath;
use App\Services\Ems\MasterInventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything the supply room screen loads once and keeps on the tablet (docs/ems/supply-room-kiosk.md section 3):
 * items with their units and search words, members as "Rank Name" only, units, shelves, shorthand.
 */
final class KioskCatalog
{
    private const POPULAR_DAYS = 90;

    private const MOST_TAKEN = 12;

    /** Apparatus designations that are placeholders, not units a member would pick. */
    private const HIDDEN_UNITS = ['reserve'];

    /** @return array<string, mixed> */
    public function bootstrap(KioskDevice $device): array
    {
        $popularity = $this->popularity();
        $items = $this->items($popularity);

        return [
            'device' => ['name' => $device->name],
            'csrf_token' => csrf_token(),
            'generated_at' => now()->toIso8601String(),
            'members' => $this->members(),
            'units' => $this->units(),
            'locations' => DB::table('ems_storage_locations')->where('active', true)->orderBy('sort')->orderBy('code')
                ->get(['code', 'label', 'sort'])->map(fn ($row): array => ['code' => $row->code, 'label' => $row->label, 'sort' => (int) $row->sort])->all(),
            'items' => $items,
            'most_taken' => $this->mostTaken($popularity, array_column($items, 'id')),
            'shorthand' => KioskShorthand::MAP,
        ];
    }

    /**
     * Units a member can take an item in: always "each", plus the count unit and the package unit when one holds
     * more than one. The withdrawal is checked against this same list.
     *
     * @return list<array{key: string, label: string, plural: string, per: int}>
     */
    public static function unitsFor(SupplyItem $item): array
    {
        $units = [['key' => 'each', 'label' => 'each', 'plural' => 'each', 'per' => 1]];
        $size = (int) $item->units_per_package;
        foreach ([$item->count_unit, $item->package_unit] as $unit) {
            if (! is_string($unit) || $unit === 'each' || $size < 2 || $unit !== $item->package_unit || in_array($unit, array_column($units, 'key'), true)) {
                continue;
            }
            [$one, $many] = CountMath::LABELS[$unit] ?? [$unit, $unit.'s'];
            $units[] = ['key' => $unit, 'label' => "{$one} of {$size}", 'plural' => "{$many} of {$size}", 'per' => $size];
        }

        return $units;
    }

    /** @return list<array{employee_id: string, name: string}> */
    public function members(): array
    {
        $rows = self::activeEmployees()->whereNotNull('employee_id')->orderBy('name')->get(['employee_id', 'name', 'rank']);
        $counts = $rows->countBy(fn (Employee $employee): string => (string) $employee->employee_id);

        return $rows->filter(fn (Employee $employee): bool => trim((string) $employee->employee_id) !== '' && $counts[(string) $employee->employee_id] === 1)
            ->map(fn (Employee $employee): array => ['employee_id' => (string) $employee->employee_id, 'name' => self::displayName($employee)])
            ->values()->all();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Employee> */
    public static function activeEmployees(): \Illuminate\Database\Eloquent\Builder
    {
        return Employee::query()->where(fn ($query) => $query->whereNull('roster_status')->orWhere('roster_status', 'active'));
    }

    public static function displayName(Employee $employee): string
    {
        return trim(trim((string) $employee->rank).' '.trim((string) $employee->name));
    }

    /** @return list<array{id: int, label: string}> */
    public function units(): array
    {
        $order = ['R' => 0, 'E' => 1, 'L' => 2, 'A' => 3];

        return Apparatus::query()->whereNotNull('designation')->orderBy('id')->get(['id', 'designation'])
            ->filter(fn (Apparatus $unit): bool => ! in_array(mb_strtolower(trim((string) $unit->designation)), self::HIDDEN_UNITS, true)
                && ! str_starts_with(mb_strtoupper((string) $unit->designation), 'ZZ'))
            ->unique(fn (Apparatus $unit): string => mb_strtoupper(trim((string) $unit->designation)))
            ->sortBy(fn (Apparatus $unit): string => sprintf('%d|%s|%05d',
                $order[mb_strtoupper(mb_substr(trim((string) $unit->designation), 0, 1))] ?? 9,
                mb_strtoupper((string) preg_replace('/[\d\s]+/', '', (string) $unit->designation)),
                (int) preg_replace('/\D+/', '', (string) $unit->designation)))
            ->map(fn (Apparatus $unit): array => ['id' => (int) $unit->id, 'label' => trim((string) $unit->designation)])
            ->values()->all();
    }

    /**
     * @param  array<int, int>  $popularity
     * @return list<array<string, mixed>>
     */
    private function items(array $popularity): array
    {
        $learned = KioskSearchTerm::query()->where('status', KioskSearchTerm::APPROVED)->orderBy('term')->get(['supply_item_id', 'term'])
            ->groupBy('supply_item_id')->map(fn (Collection $terms): array => $terms->pluck('term')->values()->all());

        return SupplyItem::query()->where('active', true)->where('controlled_substance', false)
            ->with(['location:id,code,label', 'catalogItem:id,name,category,aliases'])->orderBy('name')->get()
            ->map(function (SupplyItem $item) use ($popularity, $learned): array {
                $units = self::unitsFor($item);
                $expected = $item->threshold_qty_base;

                return [
                    'id' => (int) $item->id,
                    'name' => $item->name,
                    'location_code' => $item->location?->code,
                    'location_label' => $item->location?->label,
                    'section_label' => MasterInventoryService::SECTION_LABELS[(string) $item->section_key] ?? ($item->section_key !== null ? Str::of($item->section_key)->replace('-', ' ')->ucfirst()->toString() : null),
                    'category' => $item->catalogItem?->category,
                    'aliases' => $this->aliases($item),
                    'learned_aliases' => $learned->get($item->id, []),
                    'item_numbers' => array_values(array_filter(array_map(static fn ($n): string => trim((string) $n), (array) ($item->item_numbers ?? [])))),
                    'units' => $units,
                    'default_unit' => in_array($item->count_unit, array_column($units, 'key'), true) ? $item->count_unit : 'each',
                    'confirm_above_base' => $expected !== null && $expected > 1 ? intdiv($expected, 2) : null,
                    'popularity' => $popularity[$item->id] ?? 0,
                ];
            })->values()->all();
    }

    /** @return list<string> */
    private function aliases(SupplyItem $item): array
    {
        $names = [$item->bag_alias_text, $item->catalogItem?->name, ...((array) ($item->catalogItem->aliases ?? []))];

        return collect($names)->map(fn ($name): string => trim((string) $name))
            ->filter(fn (string $name): bool => $name !== '' && mb_strtolower($name) !== mb_strtolower($item->name))
            ->unique(fn (string $name): string => mb_strtolower($name))->values()->all();
    }

    /** @return array<int, int> supply_item_id => kiosk lines in the last 90 days */
    private function popularity(): array
    {
        return DB::table('ems_kiosk_withdrawal_lines as l')
            ->join('ems_kiosk_withdrawals as w', 'w.id', '=', 'l.withdrawal_id')
            ->where('w.status', KioskWithdrawal::TAKEN)->where('w.taken_at', '>=', now()->subDays(self::POPULAR_DAYS))
            ->groupBy('l.supply_item_id')->selectRaw('l.supply_item_id as id, count(*) as n')
            ->pluck('n', 'id')->map(fn ($n): int => (int) $n)->all();
    }

    /**
     * Most taken at the screen; topped up with what the supply room issued most, so a new screen is useful on day one.
     *
     * @param  array<int, int>  $popularity
     * @param  list<int>  $shown
     * @return list<int>
     */
    private function mostTaken(array $popularity, array $shown): array
    {
        arsort($popularity);
        $ids = array_keys($popularity);
        if (count($ids) < self::MOST_TAKEN) {
            $issued = StockMovement::query()->where('movement_type', StockMovement::ISSUED)->where('occurred_at', '>=', now()->subDays(self::POPULAR_DAYS))
                ->groupBy('supply_item_id')->selectRaw('supply_item_id, count(*) as n')->orderByDesc('n')->limit(self::MOST_TAKEN * 2)
                ->pluck('supply_item_id')->map(fn ($id): int => (int) $id)->all();
            $ids = array_values(array_unique([...$ids, ...$issued]));
        }

        return array_slice(array_values(array_intersect($ids, $shown)), 0, self::MOST_TAKEN);
    }
}
