<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stock line in the Firefighting PPE / Uniforms inventory.
 *
 * Every descriptive field is optional; item_name is derived from the type,
 * make and model when the person entering stock leaves it blank.
 */
class Uniform extends Model
{
    public const UNNAMED_ITEM = 'Unnamed item';

    protected $fillable = [
        'item_name',
        'category',
        'item_type',
        'manufacturer',
        'model',
        'size',
        'color',
        'serial_number',
        'manufacture_date',
        'condition',
        'storage_location',
        'quantity_on_hand',
        'reorder_level',
        'unit_cost',
        'supplier',
        'notes',
    ];

    protected $casts = [
        'quantity_on_hand' => 'integer',
        'reorder_level' => 'integer',
        'unit_cost' => 'decimal:2',
        'manufacture_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (Uniform $uniform): void {
            $uniform->item_name = filled($uniform->item_name)
                ? trim((string) $uniform->item_name)
                : $uniform->derivedName();
            $uniform->quantity_on_hand = max(0, (int) ($uniform->quantity_on_hand ?? 0));
            $uniform->reorder_level = max(0, (int) ($uniform->reorder_level ?? 0));
        });
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssignedEquipment::class);
    }

    /** Name built from the optional detail fields, used when no name was typed. */
    public function derivedName(): string
    {
        $parts = array_filter([$this->manufacturer, $this->model, $this->item_type], 'filled');

        return $parts === [] ? self::UNNAMED_ITEM : implode(' ', $parts);
    }

    /** Short description recorded on an assignment, e.g. "Globe G-Xtreme Bunker Coat — Size 44". */
    public function issueDescription(): string
    {
        $description = (string) $this->item_name;
        if (filled($this->item_type) && ! str_contains(strtolower($description), strtolower((string) $this->item_type))) {
            $description .= " ({$this->item_type})";
        }
        if (filled($this->size)) {
            $description .= " — Size {$this->size}";
        }
        if (filled($this->serial_number)) {
            $description .= " — S/N {$this->serial_number}";
        }

        return $description;
    }

    /** Label for stock pickers: description plus how many are on hand. */
    public function stockLabel(): string
    {
        return $this->issueDescription()." — {$this->quantity_on_hand} on hand";
    }

    public function isPpe(): bool
    {
        return in_array(strtolower((string) $this->category), ['firefighting ppe', 'ppe', 'footwear'], true);
    }
}
