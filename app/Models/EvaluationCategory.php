<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'is_rankable' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
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
}
