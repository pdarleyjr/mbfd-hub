<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMutation extends Model
{
    protected $fillable = [
        'stocker_type',
        'stocker_id',
        'reference',
        'amount',
        'description',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    /**
     * Get the parent stocker model (EquipmentItem, etc.)
     */
    public function stocker(): MorphTo
    {
        return $this->morphTo();
    }
}
