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

    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(WorkgroupFile::class);
    }

    public function sharedUploads(): HasMany
    {
        return $this->hasMany(WorkgroupSharedUpload::class);
    }

    public function candidateProducts(): HasMany
    {
        return $this->hasMany(CandidateProduct::class, 'workgroup_session_id');
    }

    /**
     * Users assigned to this session (pivot with is_official_evaluator).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'session_user', 'workgroup_session_id', 'user_id')
            ->withPivot('is_official_evaluator')
            ->withTimestamps();
    }

    /**
     * Official evaluators for this session.
     */
    public function officialEvaluators(): BelongsToMany
    {
        return $this->users()->wherePivot('is_official_evaluator', true);
    }

    /**
     * Get evaluation submissions for this session.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(EvaluationSubmission::class, 'session_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
