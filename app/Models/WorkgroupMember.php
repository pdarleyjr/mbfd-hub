<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkgroupMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'workgroup_id',
        'user_id',
        'role',
        'is_active',
        'count_evaluations',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'count_evaluations' => 'boolean',
    ];

    /**
     * Virtual 'name' attribute — returns the linked user's display name.
     * Used by Filament forms (CheckboxList / Select relationship lookups).
     * NOTE: must NOT use int type hint (AI_AGENT_ERRORS.md ERROR-002 pattern).
     */
    public function getNameAttribute($value = null): string
    {
        return $this->user?->name ?? "Member #{$this->id}";
    }

    protected $appends = [
        'name',
    ];

    /**
     * Get the workgroup this member belongs to.
     */
    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    /**
     * Get the user associated with this member record.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all notes created by this member.
     */
    public function notes(): HasMany
    {
        return $this->hasMany(WorkgroupNote::class);
    }

    /**
     * Get all shared uploads by this member.
     */
    public function sharedUploads(): HasMany
    {
        return $this->hasMany(WorkgroupSharedUpload::class);
    }

    /**
     * Get all evaluation submissions by this member.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(EvaluationSubmission::class, 'workgroup_member_id');
    }

    /**
     * Scope to get only active members.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get only members whose evaluations count toward results.
     */
    public function scopeCountable($query)
    {
        return $query->where('count_evaluations', true);
    }

    /**
     * Check if member is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if member is a facilitator.
     */
    public function isFacilitator(): bool
    {
        return in_array($this->role, ['admin', 'facilitator']);
    }

    /**
     * Get all sessions this member attended (pivot table).
     */
    public function sessionsAttended(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\WorkgroupSession::class,
            'session_workgroup_member_attendance',
            'workgroup_member_id',
            'workgroup_session_id'
        )->withTimestamps();
    }
}
