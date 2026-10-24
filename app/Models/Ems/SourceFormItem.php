<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $source_form_id
 * @property string $item_key
 * @property int|null $catalog_item_id
 * @property string $section_key
 * @property string $section_label
 * @property string|null $parent_section_key
 * @property string $label
 * @property string $source_label
 * @property string|null $min_text
 * @property int|null $min_qty
 * @property string|null $min_unit
 * @property string|null $min_applies_to
 * @property string|null $variant_group
 * @property string|null $variant_label
 * @property string $response_type
 * @property array<string, mixed>|null $flags
 * @property string|null $notes
 * @property bool $needs_review
 * @property string|null $review_reason
 * @property array<string, mixed>|null $source_ref
 * @property int $sort
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class SourceFormItem extends Model
{
    public const QUANTITY = 'quantity';

    public const YES_NO = 'yes_no';

    protected $table = 'ems_source_form_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'min_qty' => 'integer',
            'flags' => 'array',
            'needs_review' => 'boolean',
            'source_ref' => 'array',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<SourceForm, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(SourceForm::class, 'source_form_id');
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    public function flag(string $name): bool
    {
        return (bool) (($this->flags ?? [])[$name] ?? false);
    }
}
