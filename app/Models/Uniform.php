<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Uniform extends Model
{
    protected $fillable = [
        'item_name',
        'size',
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
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(AssignedEquipment::class);
    }
}
