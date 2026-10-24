<?php

declare(strict_types=1);

namespace App\Models\Ems;

use App\Models\Ems\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only stock ledger row (signed base units: issued < 0, received > 0, adjusted either way).
 * A correction is a new `adjusted` row whose corrects_movement_id points at the row it offsets.
 *
 * @property int $id
 * @property string $uuid
 * @property int $supply_item_id
 * @property string $movement_type
 * @property int $quantity_base
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property int|null $actor_user_id
 * @property string|null $actor_name
 * @property string|null $reason
 * @property int|null $request_id
 * @property int|null $request_line_id
 * @property int|null $supply_order_id
 * @property int|null $corrects_movement_id
 * @property string $idempotency_key
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read int|string|null $issued Per-item sum added by StockLedger::movementSums()
 * @property-read int|string|null $received Per-item sum added by StockLedger::movementSums()
 * @property-read int|string|null $adjusted Per-item sum added by StockLedger::movementSums()
 * @property-read int|string|null $n Movement count added by StockLedger::movementSums()
 * @property-read int|string|null $last_id Newest movement id added by StockLedger::movementSums()
 */
class StockMovement extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const ISSUED = 'issued';

    public const RECEIVED = 'received';

    public const ADJUSTED = 'adjusted';

    protected $table = 'ems_stock_movements';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity_base' => 'integer', 'occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<SupplyItem, $this> */
    public function supplyItem(): BelongsTo
    {
        return $this->belongsTo(SupplyItem::class, 'supply_item_id');
    }
}
