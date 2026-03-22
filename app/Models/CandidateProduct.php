<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandidateProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'workgroup_session_id',
        'category_id',
        'name',
        'manufacturer',
        'brand',
        'competitor_group',
        'model',
        'description',
    ];

    /**
     * Get the session this product belongs to.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkgroupSession::class, 'workgroup_session_id');
    }

    /**
     * Get the category this product belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EvaluationCategory::class, 'category_id');
    }

    /**
     * Get all submissions for this product.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(EvaluationSubmission::class, 'candidate_product_id');
    }

    /**
     * Get the workgroup this product belongs to through the session.
     */
    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    /**
     * Get display name with manufacturer and model.
     */
    public function getDisplayNameAttribute(): string
    {
        $parts = array_filter([
            $this->manufacturer,
            $this->model,
        ]);

        return $this->name . ($parts ? ' (' . implode(' ', $parts) . ')' : '');
    }

    /**
     * Get the effective brand (falls back to manufacturer if brand is null).
     */
    public function getEffectiveBrandAttribute(): ?string
    {
        return $this->brand ?? $this->manufacturer;
    }

    /**
     * Check if this product is standalone (no competitor group peers).
     */
    public function isStandalone(): bool
    {
        return $this->competitor_group === 'standalone';
    }
}
