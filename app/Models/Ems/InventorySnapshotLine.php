<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property int $id
 * @property int $snapshot_id
 * @property int $supply_item_id
 * @property string|null $location_code
 * @property string $item_name
 * @property int|null $quantity_base
 * @property array<string, mixed>|null $entry
 * @property string $value_source
 * @property array<int|string, mixed>|null $warnings
 * @property int|null $through_movement_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class InventorySnapshotLine extends Model
{
    public const COUNTED = 'counted';

    public const CALCULATED = 'calculated';

    public const NOT_COUNTED = 'not_counted';

    protected $table = 'ems_inventory_snapshot_lines';

    protected $guarded = [];

    protected static function booted(): void
    {
        $guard = static function (self $line): void {
            $status = DB::table('ems_inventory_snapshots')->where('id', $line->snapshot_id)->value('status');
            if ($status === InventorySnapshot::FINAL) {
                throw new LogicException('Lines of a final inventory snapshot are immutable.');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['through_movement_id' => 'integer', 'quantity_base' => 'integer', 'entry' => 'array', 'warnings' => 'array'];
    }

    /** @return BelongsTo<InventorySnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(InventorySnapshot::class, 'snapshot_id');
    }

    /** @return BelongsTo<SupplyItem, $this> */
    public function supplyItem(): BelongsTo
    {
        return $this->belongsTo(SupplyItem::class, 'supply_item_id');
    }
}
