<?php

namespace App\Models;

use App\Support\Workgroups\UniversalEvaluationRubric;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_rankable',
        'display_order',
        'is_active',
        'assessment_profile',
        'instructions_markdown',
        'score_visibility_notes',
        'finalists_limit',
    ];

    protected $casts = [
        'is_rankable' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
        'finalists_limit' => 'integer',
    ];

    /**
     * Get all templates in this category.
     */
    public function templates(): HasMany
    {
        return $this->hasMany(EvaluationTemplate::class, 'category_id');
    }

    /**
     * Get all candidate products in this category.
     */
    public function candidateProducts(): HasMany
    {
        return $this->hasMany(CandidateProduct::class, 'category_id');
    }

    /**
     * Scope to get only active categories.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get rankable categories.
     */
    public function scopeRankable($query)
    {
        return $query->where('is_rankable', true);
    }

    /**
     * Scope to order by display order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    /**
     * Get the assessment profile for this category.
     * Falls back to heuristic detection based on category name if not set.
     */
    public function getAssessmentProfileAttribute(?string $value): string
    {
        if ($value && in_array($value, UniversalEvaluationRubric::getAssessmentProfiles())) {
            return $value;
        }
        
        // Fall back to heuristic detection from name
        return UniversalEvaluationRubric::getProfileForCategory($this->name ?? '');
    }

    /**
     * Get the display name for the assessment profile.
     */
    public function getAssessmentProfileLabelAttribute(): string
    {
        $profiles = UniversalEvaluationRubric::getAssessmentProfiles();
        return $profiles[$this->assessment_profile] ?? 'Generic Apparatus';
    }

    /**
     * Get evaluator instructions (markdown) or default instructions.
     */
    public function getEvaluatorInstructionsAttribute(): string
    {
        if ($this->instructions_markdown) {
            return $this->instructions_markdown;
        }
        
        return UniversalEvaluationRubric::getEvaluatorInstructions();
    }

    /**
     * Check if this category has a specific assessment profile.
     */
    public function hasProfile(string $profile): bool
    {
        return $this->assessment_profile === $profile;
    }

    /**
     * Get the finalists limit for this category.
     */
    public function getFinalistsLimitAttribute(?int $value): int
    {
        return $value ?? 2; // Default to top 2
    }
}
