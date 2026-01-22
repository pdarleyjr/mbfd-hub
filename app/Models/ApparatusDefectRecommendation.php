<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApparatusDefectRecommendation extends Model
{
    protected $fillable = [
        'apparatus_defect_id',
        'equipment_item_id',
        'match_method',
        'match_confidence',
        'recommended_qty',
        'reasoning',
        'status',
        'created_by_user_id',
    ];

    protected $casts = [
        'match_confidence' => 'decimal:4',
        'recommended_qty' => 'integer',
    ];

    /**
     * Get the related defect
     */
    public function defect(): BelongsTo
    {
        return $this->belongsTo(ApparatusDefect::class, 'apparatus_defect_id');
    }

    /**
     * Get the recommended equipment item
     */
    public function equipmentItem(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class, 'equipment_item_id');
    }

    /**
     * Get the user who created this recommendation
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Scope for pending recommendations
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for allocated recommendations
     */
    public function scopeAllocated($query)
    {
        return $query->where('status', 'allocated');
    }
}
