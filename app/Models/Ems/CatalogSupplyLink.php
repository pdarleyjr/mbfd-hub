<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A decided answer to "which supply room item does this form item take stock from?". It exists for the
 * form items whose catalog identity is not shared with a supply room row. `reviewed` rows come from the
 * tracked mapping file, `person` rows from Captain 5 at reconciliation; an importer never replaces a person's.
 *
 * @property int $id
 * @property int $catalog_item_id
 * @property int|null $supply_item_id
 * @property bool $not_stocked
 * @property string $source
 * @property int|null $decided_by_user_id
 * @property string|null $decided_name
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CatalogSupplyLink extends Model
{
    public const SOURCE_REVIEWED = 'reviewed';

    public const SOURCE_PERSON = 'person';

    protected $table = 'ems_catalog_supply_links';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['not_stocked' => 'boolean'];
    }

    /** @return BelongsTo<SupplyItem, $this> */
    public function supplyItem(): BelongsTo
    {
        return $this->belongsTo(SupplyItem::class, 'supply_item_id');
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }
}
