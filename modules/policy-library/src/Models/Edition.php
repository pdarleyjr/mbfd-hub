<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Edition extends Model
{
    protected $table = 'policy_editions';

    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array', 'published_at' => 'datetime'];

    /** @return BelongsTo<Manual, $this> */
    public function manual(): BelongsTo
    {
        return $this->belongsTo(Manual::class);
    }

    /** @return HasMany<ManualNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(ManualNode::class);
    }
}
