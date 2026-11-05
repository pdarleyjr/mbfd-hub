<?php

declare(strict_types=1);

namespace App\Services\Ems\Kiosk;

use App\Models\Ems\KioskSearchTerm;
use App\Models\Ems\SupplyItem;
use App\Models\User;
use App\Services\Ems\EmsAccess;
use App\Services\Ems\EmsActor;
use App\Services\Ems\EmsException;
use Illuminate\Support\Facades\DB;

/**
 * Search words that learn, safely (docs/ems/supply-room-kiosk.md section 6): what a member typed before picking an
 * item is counted; after three picks of the same item it is offered to Captain 5, and only an approved word reaches
 * the screen's search.
 */
final class KioskSearchLearning
{
    public function __construct(private readonly EmsAccess $access) {}

    public static function normalize(string $term): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($term))), 0, 80);
    }

    /** @param list<array{term: string, supply_item_id: int|string}> $searches */
    public function record(array $searches): void
    {
        $seen = [];
        $names = SupplyItem::query()->whereIn('id', array_map(fn (array $s): int => (int) $s['supply_item_id'], $searches))->pluck('name', 'id');
        foreach ($searches as $search) {
            $term = self::normalize((string) $search['term']);
            $itemId = (int) $search['supply_item_id'];
            $key = $term.'|'.$itemId;
            // A word that is just the item's own name teaches search nothing.
            if (mb_strlen($term) < 2 || isset($seen[$key]) || $term === mb_strtolower((string) ($names[$itemId] ?? ''))) {
                continue;
            }
            $seen[$key] = true;
            DB::table('ems_kiosk_search_terms')->insertOrIgnore([
                'term' => $term, 'supply_item_id' => $itemId, 'picks' => 0, 'status' => KioskSearchTerm::LEARNING,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            KioskSearchTerm::query()->where('term', $term)->where('supply_item_id', $itemId)
                ->update(['picks' => DB::raw('picks + 1'), 'last_picked_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Words picked often enough to ask Captain 5 about, most picked first.
     *
     * @return list<array{id: int, term: string, supply_item_id: int, item_name: string, picks: int, last_picked_at: ?string}>
     */
    public function suggestions(): array
    {
        return KioskSearchTerm::query()->with('item:id,name')->where('status', KioskSearchTerm::LEARNING)
            ->where('picks', '>=', KioskSearchTerm::SUGGEST_AT)->orderByDesc('picks')->orderBy('term')->limit(200)->get()
            ->map(fn (KioskSearchTerm $term): array => [
                'id' => $term->id, 'term' => $term->term, 'supply_item_id' => $term->supply_item_id,
                'item_name' => (string) $term->item?->name, 'picks' => $term->picks, 'last_picked_at' => $term->last_picked_at?->toIso8601String(),
            ])->all();
    }

    /**
     * @param  list<int>  $approve
     * @param  list<int>  $dismiss
     */
    public function decide(array $approve, array $dismiss, User $user): void
    {
        if (! $this->access->canEditInventory($user)) {
            throw EmsException::forbidden('Only Captain 5 or an administrator can approve search words.');
        }
        $by = EmsActor::forUser($user, 'captain5')->name;
        DB::transaction(function () use ($approve, $dismiss, $by): void {
            KioskSearchTerm::query()->whereIn('id', $approve)->update(['status' => KioskSearchTerm::APPROVED, 'decided_by_name' => $by, 'decided_at' => now()]);
            KioskSearchTerm::query()->whereIn('id', array_values(array_diff($dismiss, $approve)))->update(['status' => KioskSearchTerm::DISMISSED, 'decided_by_name' => $by, 'decided_at' => now()]);
        });
    }
}
