<?php

namespace App\Domain\ApparatusLayout;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApparatusCompartment extends Model
{
    protected $fillable = [
        'apparatus_id',
        'label',
        'side',
        'width',
        'height',
        'depth',
        'shelf_type',
        'shelf_count',
        'has_pegboard',
        'pegboard_faces',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'depth' => 'decimal:2',
        'shelf_count' => 'integer',
        'has_pegboard' => 'boolean',
        'pegboard_faces' => 'array',
        'sort_order' => 'integer',
    ];

    /**
     * Get the apparatus this compartment belongs to.
     */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Apparatus::class);
    }

    /**
     * Get dimensions as array.
     */
    public function getDimensions(): array
    {
        return [
            'width' => (float) $this->width,
            'height' => (float) $this->height,
            'depth' => (float) $this->depth,
        ];
    }

    /**
     * Get shelf type color for rendering.
     */
    public function getShelfTypeColor(): string
    {
        return match ($this->shelf_type) {
            'fixed' => '#ef4444',    // red
            'pull-out' => '#3b82f6', // blue
            'assisted' => '#06b6d4', // cyan
            'split' => '#a855f7',    // purple
            default => '#6b7280',    // gray
        };
    }
}