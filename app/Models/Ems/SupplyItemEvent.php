<?php

declare(strict_types=1);

namespace App\Models\Ems;

use App\Models\Ems\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit of threshold, packaging and location edits.
 *
 * @property int $id
 * @property int $supply_item_id
 * @property int|null $user_id
 * @property string|null $actor_name
 * @property string $field
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string $reason
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class SupplyItemEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'ems_supply_item_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    /** @return BelongsTo<SupplyItem, $this> */
    public function supplyItem(): BelongsTo
    {
        return $this->belongsTo(SupplyItem::class, 'supply_item_id');
    }
}
