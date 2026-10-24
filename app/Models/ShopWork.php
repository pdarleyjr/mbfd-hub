<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopWork extends Model
{
    /** Columns the shop-need form is allowed to write (priority, category and quantity are not on the form). */
    protected $fillable = [
        'project_name',
        'description',
        'apparatus_id',
        'status',
        'parts_list',
        'estimated_cost',
        'actual_cost',
        'started_date',
        'completed_date',
        'assigned_to',
        'notes',
    ];

    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }
}
