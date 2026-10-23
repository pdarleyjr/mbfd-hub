<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One approval applied to one or more apparatus, with the per-unit result of each. */
final class ApparatusInventoryChangeSet extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_REVERSED = 'reversed';

    protected $guarded = ['id'];

    protected $casts = [
        'operation' => 'array',
        'results' => 'array',
        'reversed_at' => 'datetime',
    ];

    /** @return BelongsTo<ApparatusInventoryCorrection, $this> */
    public function correction(): BelongsTo
    {
        return $this->belongsTo(ApparatusInventoryCorrection::class, 'apparatus_inventory_correction_id');
    }

    /** @return HasMany<ApparatusInventoryChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(ApparatusInventoryChange::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
