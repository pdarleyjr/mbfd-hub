<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'workgroup_member_id',
        'candidate_product_id',
        'status',
        'submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    /**
     * Get the member who created this submission.
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(WorkgroupMember::class, 'workgroup_member_id');
    }

    /**
     * Get the candidate product being evaluated.
     */
    public function candidateProduct(): BelongsTo
    {
        return $this->belongsTo(CandidateProduct::class, 'candidate_product_id');
    }

    /**
     * Get all scores for this submission.
     */
    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class, 'submission_id');
    }

    /**
     * Get all comments for this submission.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(EvaluationComment::class, 'submission_id');
    }

    /**
     * Scope to get draft submissions.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope to get submitted evaluations.
     */
    public function scopeSubmitted($query)
    {
        return $query->where('status', 'submitted');
    }

    /**
     * Check if submission is a draft.
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if submission has been submitted.
     */
    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    /**
     * Calculate total weighted score.
     */
    public function getTotalScoreAttribute(): ?float
    {
        $totalScore = 0;
        $totalWeight = 0;

        foreach ($this->scores as $score) {
            if ($score->score !== null) {
                $totalScore += $score->score * $score->criterion->weight;
                $totalWeight += $score->criterion->weight;
            }
        }

        return $totalWeight > 0 ? round($totalScore / $totalWeight, 2) : null;
    }
}
