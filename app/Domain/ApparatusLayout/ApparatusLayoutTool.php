<?php

namespace App\Domain\ApparatusLayout;

use Illuminate\Database\Eloquent\Model;

class ApparatusLayoutTool extends Model
{
    protected $fillable = [
        'name',
        'category',
        'length',
        'width',
        'height',
        'weight',
        'can_rotate',
        'requires_clearance',
        'clearance_depth',
        'icon_path',
        'color',
        'notes',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'weight' => 'decimal:2',
        'clearance_depth' => 'decimal:2',
        'can_rotate' => 'boolean',
        'requires_clearance' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Get dimensions as array.
     */
    public function getDimensions(): array
    {
        return [
            'length' => (float) $this->length,
            'width' => (float) $this->width,
            'height' => (float) $this->height,
        ];
    }

    /**
     * Get icon URL.
     */
    public function getIconUrl(): ?string
    {
        if ($this->icon_path) {
            return asset($this->icon_path);
        }
        return null;
    }

    /**
     * Scope to get active tools.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by sort_order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Scope to filter by category.
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}