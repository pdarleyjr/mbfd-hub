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
        'user_id',
        'candidate_product_id',
        'session_id',
        'status',
        'is_locked',
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
        'is_locked' => 'boolean',
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
     * Get the user who created this submission.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the candidate product being evaluated.
     */
    public function candidateProduct(): BelongsTo
    {
        return $this->belongsTo(CandidateProduct::class, 'candidate_product_id');
    }

    /**
     * Get the session this submission belongs to.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkgroupSession::class, 'session_id');
    }

    /**
     * Get all scores for this submission (legacy).
     */
    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class, 'submission_id');
    }

    /**
     * Get all comments for this submission (legacy).
     */
    public function comments(): HasMany
    {
        return $this->hasMany(EvaluationComment::class, 'submission_id');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', 'submitted');
    }

    public function scopeLocked($query)
    {
        return $query->where('is_locked', true);
    }

    public function scopeUnlocked($query)
    {
        return $query->where('is_locked', false);
    }

    public function scopeOfficialOnly($query)
    {
        return $query->whereHas('user', function ($q) {
            $q->whereExists(function ($sub) {
                $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('session_user')
                    ->whereColumn('session_user.user_id', 'users.id')
                    ->where('session_user.is_official_evaluator', true);
            });
        });
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isLocked(): bool
    {
        return (bool) $this->is_locked;
    }

    public function getCriterionRatings(): array
    {
        return $this->criterion_payload['ratings'] ?? [];
    }

    public function getCriterionNotes(): array
    {
        return $this->criterion_payload['notes'] ?? [];
    }

    public function getNarrative(): array
    {
        return $this->narrative_payload ?? [];
    }

    public function calculateRubricScores(): void
    {
        $ratings = $this->getCriterionRatings();
        if (empty($ratings)) return;

        $scores = UniversalEvaluationRubric::calculateAllScores($ratings);
        $this->rubric_version = UniversalEvaluationRubric::getVersion();
        $this->overall_score = $scores['overall_score'];
        $this->capability_score = $scores['capability_score'];
        $this->usability_score = $scores['usability_score'];
        $this->affordability_score = $scores['affordability_score'];
        $this->maintainability_score = $scores['maintainability_score'];
        $this->deployability_score = $scores['deployability_score'];
    }

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

    public function getRecommendationLabelAttribute(): string
    {
        return match($this->advance_recommendation) {
            'yes' => 'Advance to Finalist',
            'maybe' => 'Needs Further Review',
            'no' => 'Do Not Advance',
            default => 'Pending',
        };
    }

    public function getConfidenceLabelAttribute(): string
    {
        return match($this->confidence_level) {
            'high' => 'High Confidence',
            'medium' => 'Medium Confidence',
            'low' => 'Low Confidence',
            default => 'Not Specified',
        };
    }

    public function hasUniversalRubric(): bool
    {
        return !empty($this->rubric_version) && !empty($this->criterion_payload);
    }

    public function getTotalScoreAttribute(): ?float
    {
        if ($this->hasUniversalRubric() && $this->overall_score !== null) {
            return $this->overall_score;
        }
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
