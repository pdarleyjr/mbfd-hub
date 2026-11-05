<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A word a member typed before picking an item at the supply room screen. Only an approved word reaches search.
 *
 * @property int $id
 * @property string $term
 * @property int $supply_item_id
 * @property int $picks
 * @property \Illuminate\Support\Carbon|null $last_picked_at
 * @property string $status
 * @property string|null $decided_by_name
 * @property \Illuminate\Support\Carbon|null $decided_at
 */
class KioskSearchTerm extends Model
{
    public const LEARNING = 'learning';

    public const APPROVED = 'approved';

    public const DISMISSED = 'dismissed';

    /** Picks of the same item for the same word before Captain 5 is asked about it. */
    public const SUGGEST_AT = 3;

    protected $table = 'ems_kiosk_search_terms';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['picks' => 'integer', 'last_picked_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<SupplyItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SupplyItem::class, 'supply_item_id');
    }
}
