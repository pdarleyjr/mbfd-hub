<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopWork extends Model
{
    /** Allowed values of the `shop_works.status` database enum. */
    public const STATUSES = ['Pending', 'In Progress', 'Waiting for Parts', 'Completed', 'Cancelled'];

    protected $fillable = [
        'project_name',
        'category',
        'description',
        'quantity',
        'apparatus_id',
        'status',
        'priority',
        'parts_list',
        'estimated_cost',
        'actual_cost',
        'started_date',
        'completed_date',
        'assigned_to',
        'notes',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'started_date' => 'date',
        'completed_date' => 'date',
    ];

    /** @return BelongsTo<Apparatus, \> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }
}
