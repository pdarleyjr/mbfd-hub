<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeEquipmentRequest extends Model
{
    protected $fillable = [
        'user_id',
        'employee_portal_id',
        'requested_items',
        'status',
        'admin_notes',
        'reason',
        'is_archived',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'is_archived' => 'boolean',
    ];

    /**
     * Full status workflow:
     * Pending → Ordered → Ready for Pickup → Completed (archived)
     *         ↘ Declined (auto-archived)
     */
    public const STATUSES = [
        'Pending'          => 'Pending',
        'Ordered'          => 'Ordered',
        'Ready for Pickup' => 'Ready for Pickup',
        'Completed'        => 'Completed',
        'Declined'         => 'Declined',
    ];

    // Statuses that auto-archive the request
    public const ARCHIVED_STATUSES = ['Completed', 'Declined'];

    // Preset reasons for pending/declined decisions
    public const DECLINE_REASONS = [
        'Out of stock'                 => 'Out of stock',
        'Item no longer available'     => 'Item no longer available',
        'Duplicate request'            => 'Duplicate request',
        'Exceeds allocation'           => 'Exceeds allocation',
        'Requires supervisor approval' => 'Requires supervisor approval',
        'Other'                        => 'Other',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_portal_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'Pending';
    }
}
