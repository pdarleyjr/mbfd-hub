<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationCriterion extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_id',
        'name',
        'description',
        'max_score',
        'weight',
        'display_order',
    ];

    protected $casts = [
        'max_score' => 'integer',
        'weight' => 'decimal:2',
        'display_order' => 'integer',
    ];

    /**
     * Get the template this criterion belongs to.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EvaluationTemplate::class, 'template_id');
    }

    /**
     * Get all scores for this criterion.
     */
    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class, 'criterion_id');
    }

    /**
     * Scope to order by display order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }
}
