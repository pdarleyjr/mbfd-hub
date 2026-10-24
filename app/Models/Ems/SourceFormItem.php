<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
