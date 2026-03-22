<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workgroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (Workgroup $workgroup) {
            if (empty($workgroup->created_by) && auth()->check()) {
                $workgroup->created_by = auth()->id();
            }
        });
    }

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the user who created this workgroup.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all members of this workgroup.
     */
    public function members(): HasMany
    {
        return $this->hasMany(WorkgroupMember::class);
    }

    /**
     * Get all users who are members of this workgroup.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workgroup_members')
            ->withPivot('role', 'is_active')
            ->withTimestamps();
    }

    /**
     * Get all sessions for this workgroup.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(WorkgroupSession::class);
    }

    /**
     * Get all files uploaded to this workgroup.
     */
    public function files(): HasMany
    {
        return $this->hasMany(WorkgroupFile::class);
    }

    /**
     * Get all shared uploads for this workgroup.
     */
    public function sharedUploads(): HasMany
    {
        return $this->hasMany(WorkgroupSharedUpload::class);
    }

    /**
     * Scope to get only active workgroups.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Check if a user is a member of this workgroup.
     */
    public function isMember(User $user): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Check if a user is an admin of this workgroup.
     */
    public function isAdmin(User $user): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Check if a user is a facilitator of this workgroup.
     */
    public function isFacilitator(User $user): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->whereIn('role', ['admin', 'facilitator'])
            ->where('is_active', true)
            ->exists();
    }
}
