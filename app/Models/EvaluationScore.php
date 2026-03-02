<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'criterion_id',
        'score',
    ];

    protected $casts = [
        'score' => 'decimal:2',
    ];

    /**
     * Get the submission this score belongs to.
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(EvaluationSubmission::class, 'submission_id');
    }

    /**
     * Get the criterion this score is for.
     */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(EvaluationCriterion::class, 'criterion_id');
    }

    /**
     * Get the weighted score.
     */
    public function getWeightedScoreAttribute(): ?float
    {
        if ($this->score === null) {
            return null;
        }

        return $this->score * $this->criterion->weight;
    }

    /**
     * Check if score is within valid range.
     */
    public function isValid(): bool
    {
        return $this->score !== null 
            && $this->score >= 0 
            && $this->score <= $this->criterion->max_score;
    }
}
