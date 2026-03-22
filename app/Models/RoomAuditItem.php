<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomAuditItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_audit_id',
        'room_asset_id',
        'item_type',
        'expected_quantity',
        'actual_quantity',
        'discrepancy',
        'condition_found',
        'finding_type',
        'finding_description',
        'resolution',
        'is_resolved',
        'resolved_at',
        'resolved_by',
        'notes',
    ];

    protected $casts = [
        'expected_quantity' => 'integer',
        'actual_quantity' => 'integer',
        'discrepancy' => 'integer',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    /**
     * Get the audit this item belongs to
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(RoomAudit::class, 'room_audit_id');
    }

    /**
     * Get the asset this item is about
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(RoomAsset::class, 'room_asset_id');
    }

    /**
     * Get the resolver
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Scope for unresolved items
     */
    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }

    /**
     * Scope by finding type
     */
    public function scopeByFindingType($query, string $type)
    {
        return $query->where('finding_type', $type);
    }

    /**
     * Scope for discrepancies
     */
    public function scopeWithDiscrepancy($query)
    {
        return $query->where('discrepancy', '!=', 0);
    }
}