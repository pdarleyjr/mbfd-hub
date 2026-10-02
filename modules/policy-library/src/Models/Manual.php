<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Manual extends Model
{
    protected $table = 'policy_manuals';

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    /** @return BelongsTo<Edition, $this> */
    public function activeEdition(): BelongsTo
    {
        return $this->belongsTo(Edition::class, 'active_edition_id');
    }

    /** @return HasMany<Edition, $this> */
    public function editions(): HasMany
    {
        return $this->hasMany(Edition::class);
    }

    /** @return HasMany<ManualNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(ManualNode::class);
    }
}
