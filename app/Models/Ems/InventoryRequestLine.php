<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InventoryRequestLine extends Model
{
    public const OPEN = 'open';

    public const PREPARED = 'prepared';

    public const PARTIALLY_ISSUED = 'partially_issued';

    public const ISSUED = 'issued';

    public const NOT_ISSUED = 'not_issued';

    /** What the member asked for is frozen; only the fulfilment columns move. */
    private const FROZEN = [
        'request_id', 'bag_key', 'item_key', 'label', 'section_label', 'variant_label', 'min_text', 'unit',
        'response_type', 'requested_qty', 'requested_flag', 'catalog_item_id', 'flags', 'source_form_item_id',
    ];

    protected $table = 'ems_inventory_request_lines';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(static function (self $line): void {
            foreach (self::FROZEN as $column) {
                if ($line->isDirty($column)) {
                    throw new LogicException("A submitted request line's {$column} cannot be changed.");
                }
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('Request lines are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'requested_qty' => 'integer',
            'requested_flag' => 'boolean',
            'prepared_qty' => 'integer',
            'issued_qty' => 'integer',
            'flags' => 'array',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<InventoryRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(InventoryRequest::class, 'request_id');
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    public function isYesNo(): bool
    {
        return $this->response_type === SourceFormItem::YES_NO;
    }

    /** How many are wanted: the requested quantity, or 1 for a "yes" answer. */
    public function wantedQty(): int
    {
        if ($this->isYesNo()) {
            return $this->requested_flag ? 1 : 0;
        }

        return (int) ($this->requested_qty ?? 0);
    }

    public function flag(string $name): bool
    {
        return (bool) (($this->flags ?? [])[$name] ?? false);
    }
}
