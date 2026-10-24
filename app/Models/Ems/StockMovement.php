<?php

declare(strict_types=1);

namespace App\Models\Ems;

use App\Models\Ems\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only stock ledger row (signed base units: issued < 0, received > 0, adjusted either way).
 * A correction is a new `adjusted` row whose corrects_movement_id points at the row it offsets.
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
