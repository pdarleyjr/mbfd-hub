<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\SupplyItem;
use App\Notifications\Ems\EmsLowStockNotification;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Support\Facades\DB;

/**
 * Tells the EMS low-stock alert list when supply room items drop into red
 * (docs/ems/captain5-medical-inventory-workflow.md section 12).
 *
 * Each item remembers the level the last check saw. An item that is red now and was not red then is
 * "newly red"; every newly red item from one check goes out in one message, once. An item that recovers and
 * drops again alerts again. The very first check only records the levels, so turning this on does not send
 * the whole current list. Checks run one at a time under their own lock and never take the stock lock.
 */
final class StockAlertService
{
    private const COUNTER = 'stock_alerts';

    private const ORDER_WORDS = ['pending' => 'pending', 'ordered' => 'ordered', 'backordered' => 'back ordered'];

    public function __construct(
        private readonly MasterInventoryService $master,
        private readonly NotificationDispatchService $notifications,
    ) {}

    /** @return int how many items were newly red and sent */
    public function evaluate(): int
    {
        return DB::transaction(function (): int {
            DB::table('ems_counters')->insertOrIgnore(['name' => self::COUNTER, 'value' => 0]);
            $check = (int) DB::table('ems_counters')->where('name', self::COUNTER)->lockForUpdate()->value('value');
            $baseline = $check === 0;

            $items = SupplyItem::query()->with(['location:id,code,label', 'catalogItem:id,name,aliases'])
                ->where('active', true)->where('controlled_substance', false)->orderBy('id')->get();
            $rows = collect($this->master->present($items))->keyBy('id');
            $newlyRed = [];
            foreach ($items as $item) {
                $row = $rows->get($item->getKey());
                $level = $row['level'] ?? null;
                if ($level === $item->stock_alert_level) {
                    continue;
                }
                if ($level === StockLevel::RED && ! $baseline) {
                    $newlyRed[] = $this->line($row);
                }
                // A plain update, so the item's own updated_at keeps meaning "a person changed it".
                SupplyItem::query()->whereKey($item->getKey())->toBase()->update(['stock_alert_level' => $level, 'stock_alert_level_at' => now()]);
            }
            DB::table('ems_counters')->where('name', self::COUNTER)->increment('value');

            if ($newlyRed !== []) {
                $key = 'ems_low_stock:check:'.($check + 1);
                // Written ahead inside this transaction: the levels above are saved with it, so nothing would raise it again.
                $this->notifications->dispatch(NotificationCategoryRegistry::EMS_LOW_STOCK, new EmsLowStockNotification($newlyRed, $key), eventKey: $key, writeAhead: true);
            }

            return count($newlyRed);
        });
    }

    /**
     * @param  array<string, mixed>  $row  a master list row
     * @return array{name: string, shelf: ?string, on_hand: int|float, expected: int|float, unit: string, to_order: int, order_unit: string, order_status: ?string, estimated: bool}
     */
    private function line(array $row): array
    {
        $order = $row['open_order'] ?? null;
        $toOrder = (int) ($row['to_order'] ?? 0);

        return [
            'name' => (string) $row['name'],
            'shelf' => $row['location_label'] ?? null,
            'on_hand' => $row['on_hand'] ?? 0,
            'expected' => $row['expected'] ?? 0,
            'unit' => CountMath::label((string) $row['count_unit'], $row['expected'] ?? 0),
            'to_order' => $toOrder,
            'order_unit' => CountMath::label((string) $row['count_unit'], $toOrder),
            'order_status' => $order === null ? null : (self::ORDER_WORDS[$order['status']] ?? (string) $order['status']).' ('.$order['quantity'].' '.$order['unit_label'].')',
            'estimated' => (bool) ($row['estimated'] ?? false),
        ];
    }
}
