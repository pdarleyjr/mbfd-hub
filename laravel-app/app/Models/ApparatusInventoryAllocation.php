<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApparatusInventoryAllocation extends Model
{
    protected $fillable = [
        'apparatus_id',
        'apparatus_defect_id',
        'equipment_item_id',
        'qty_allocated',
        'allocated_by_user_id',
        'allocated_at',
        'notes',
    ];

    protected $casts = [
        'qty_allocated' => 'integer',
        'allocated_at' => 'datetime',
    ];

    /**
     * Get the apparatus
     */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class, 'apparatus_id');
    }

    /**
     * Get the defect
     */
    public function defect(): BelongsTo
    {
        return $this->belongsTo(ApparatusDefect::class, 'apparatus_defect_id');
    }

    /**
     * Get the equipment item
     */
    public function equipmentItem(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class, 'equipment_item_id');
    }

    /**
     * Get the user who allocated
     */
    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by_user_id');
    }
}
