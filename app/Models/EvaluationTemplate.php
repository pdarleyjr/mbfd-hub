<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'category_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the category this template belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EvaluationCategory::class, 'category_id');
    }

    /**
     * Get all criteria for this template.
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(EvaluationCriterion::class, 'template_id');
    }

    /**
     * Scope to get only active templates.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get total possible score for this template.
     */
    public function getTotalMaxScoreAttribute(): int
    {
        return $this->criteria()->sum('max_score');
    }

    /**
     * Get total weight for this template.
     */
    public function getTotalWeightAttribute(): float
    {
        return $this->criteria()->sum('weight');
    }
}
