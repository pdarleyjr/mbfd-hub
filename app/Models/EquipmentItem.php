<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentItem extends Model
{
    protected $fillable = [
        'name',
        'description',
        'category',
        'quantity',
        'minimum_quantity',
        'location'
    ];

    // Add stock() accessor if AdminMetricsController needs it
    public function getStockAttribute(): int
    {
        return $this->quantity ?? 0;
    }
}
