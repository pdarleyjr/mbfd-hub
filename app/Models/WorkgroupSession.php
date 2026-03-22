<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkgroupSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'workgroup_id',
        'name',
        'start_date',
        'end_date',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /**
     * Get the workgroup this session belongs to.
     */
    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    /**
     * Get all files for this session.
     */
    public function files(): HasMany
    {
        return $this->hasMany(WorkgroupFile::class);
    }

    /**
     * Get all shared uploads for this session.
     */
    public function sharedUploads(): HasMany
    {
        return $this->hasMany(WorkgroupSharedUpload::class);
    }

    /**
     * Get all candidate products for this session.
     */
    public function candidateProducts(): HasMany
    {
        return $this->hasMany(CandidateProduct::class);
    }

    /**
     * Scope to get active sessions.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get draft sessions.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope to get completed sessions.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Check if session is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get all workgroup members who attended this session (pivot table).
     */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(
            WorkgroupMember::class,
            'session_workgroup_member_attendance',
            'workgroup_session_id',
            'workgroup_member_id'
        )->withTimestamps();
    }
}
