<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of a supply room screen withdrawal. `quantity_base` is the units taken (positive); the matching
 * issued movement carries it negative.
 *
 * @property int $id
 * @property int $withdrawal_id
 * @property int $supply_item_id
 * @property string $item_name
 * @property int $quantity
 * @property string $unit_key
 * @property string $unit_label
 * @property int $per
 * @property int $quantity_base
 * @property bool $shelf_showed_empty
 * @property int|null $stock_movement_id
 * @property int|null $undo_movement_id
 */
class KioskWithdrawalLine extends Model
{
    protected $table = 'ems_kiosk_withdrawal_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'per' => 'integer', 'quantity_base' => 'integer', 'shelf_showed_empty' => 'boolean'];
    }

    /** @return BelongsTo<KioskWithdrawal, $this> */
    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(KioskWithdrawal::class, 'withdrawal_id');
    }

    /** @return BelongsTo<SupplyItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SupplyItem::class, 'supply_item_id');
    }
}
