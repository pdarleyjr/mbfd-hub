<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLocation extends Model
{
    protected $fillable = [
        'location_name',
        'shelf',
        'row',
        'bin',
        'notes',
    ];

    /**
     * Get all equipment items at this location
     */
    public function equipmentItems(): HasMany
    {
        return $this->hasMany(EquipmentItem::class, 'location_id');
    }

    /**
     * Get formatted location string
     */
    public function getFullLocationAttribute(): string
    {
        $parts = [$this->location_name];
        
        if ($this->shelf) {
            $parts[] = "Shelf {$this->shelf}";
        }
        
        if ($this->row) {
            $parts[] = "Row {$this->row}";
        }
        
        if ($this->bin) {
            $parts[] = "Bin {$this->bin}";
        }
        
        return implode(' - ', $parts);
    }
}
