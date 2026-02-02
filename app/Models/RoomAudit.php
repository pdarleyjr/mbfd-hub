<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'auditor_id',
        'audit_type',
        'status',
        'scheduled_date',
        'completed_date',
        'notes',
        'findings_summary',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'completed_date' => 'date',
    ];

    /**
     * Get the room for this audit
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Get the station through room
     */
    public function station(): BelongsTo
    {
        return $this->room->belongsTo(Station::class);
    }

    /**
     * Get the auditor (user)
     */
    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }

    /**
     * Get all items found in this audit
     */
    public function items(): HasMany
    {
        return $this->hasMany(RoomAuditItem::class);
    }

    /**
     * Scope for completed audits
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'Completed');
    }

    /**
     * Scope for pending audits
     */
    public function scopePending($query)
    {
        return $query->where('status', 'Pending');
    }

    /**
     * Scope by audit type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('audit_type', $type);
    }
}