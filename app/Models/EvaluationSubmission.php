<?php

namespace App\Models;

use App\Support\Workgroups\UniversalEvaluationRubric;
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
        // Rubric versioning and profile
        'rubric_version',
        'assessment_profile',
        // Pre-calculated SAVER category scores
        'overall_score',
        'capability_score',
        'usability_score',
        'affordability_score',
        'maintainability_score',
        'deployability_score',
        // Decision metadata
        'advance_recommendation',
        'confidence_level',
        'has_deal_breaker',
        'deal_breaker_note',
        // JSON payloads
        'criterion_payload',
        'narrative_payload',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'overall_score' => 'decimal:2',
        'capability_score' => 'decimal:2',
        'usability_score' => 'decimal:2',
        'affordability_score' => 'decimal:2',
        'maintainability_score' => 'decimal:2',
        'deployability_score' => 'decimal:2',
        'has_deal_breaker' => 'boolean',
        'criterion_payload' => 'array',
        'narrative_payload' => 'array',
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
     * Get all scores for this submission (legacy - for backward compatibility).
     */
    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class, 'submission_id');
    }

    /**
     * Get all comments for this submission (legacy - for backward compatibility).
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
     * Get criterion ratings from criterion_payload JSON.
     */
    public function getCriterionRatings(): array
    {
        return $this->criterion_payload['ratings'] ?? [];
    }

    /**
     * Get criterion notes from criterion_payload JSON.
     */
    public function getCriterionNotes(): array
    {
        return $this->criterion_payload['notes'] ?? [];
    }

    /**
     * Get narrative data from narrative_payload JSON.
     */
    public function getNarrative(): array
    {
        return $this->narrative_payload ?? [];
    }

    /**
     * Calculate and store rubric scores from criterion ratings.
     */
    public function calculateRubricScores(): void
    {
        $ratings = $this->getCriterionRatings();
        
        if (empty($ratings)) {
            return;
        }

        // Use the rubric to calculate scores
        $scores = UniversalEvaluationRubric::calculateAllScores($ratings);
        
        $this->rubric_version = UniversalEvaluationRubric::getVersion();
        $this->overall_score = $scores['overall_score'];
        $this->capability_score = $scores['capability_score'];
        $this->usability_score = $scores['usability_score'];
        $this->affordability_score = $scores['affordability_score'];
        $this->maintainability_score = $scores['maintainability_score'];
        $this->deployability_score = $scores['deployability_score'];
    }

    /**
     * Get the category score for a specific SAVER bucket.
     */
    public function getCategoryScore(string $bucket): ?float
    {
        return match($bucket) {
            'capability' => $this->capability_score,
            'usability' => $this->usability_score,
            'affordability' => $this->affordability_score,
            'maintainability' => $this->maintainability_score,
            'deployability' => $this->deployability_score,
            default => null,
        };
    }

    /**
     * Get recommendation label.
     */
    public function getRecommendationLabelAttribute(): string
    {
        return match($this->advance_recommendation) {
            'yes' => 'Advance to Finalist',
            'maybe' => 'Needs Further Review',
            'no' => 'Do Not Advance',
            default => 'Pending',
        };
    }

    /**
     * Get confidence label.
     */
    public function getConfidenceLabelAttribute(): string
    {
        return match($this->confidence_level) {
            'high' => 'High Confidence',
            'medium' => 'Medium Confidence',
            'low' => 'Low Confidence',
            default => 'Not Specified',
        };
    }

    /**
     * Check if this submission uses the universal rubric.
     */
    public function hasUniversalRubric(): bool
    {
        return !empty($this->rubric_version) && !empty($this->criterion_payload);
    }

    /**
     * Calculate total weighted score (legacy support - prefers rubric scores).
     */
    public function getTotalScoreAttribute(): ?float
    {
        // If we have rubric scores, use overall_score
        if ($this->hasUniversalRubric() && $this->overall_score !== null) {
            return $this->overall_score;
        }
        
        // Fall back to legacy calculation from scores relationship
        $totalScore = 0;
        $totalWeight = 0;

        foreach ($this->scores as $score) {
            if ($score->score !== null && $score->criterion) {
                $totalScore += $score->score * $score->criterion->weight;
                $totalWeight += $score->criterion->weight;
            }
        }

        return $totalWeight > 0 ? round($totalScore / $totalWeight, 2) : null;
    }
}
