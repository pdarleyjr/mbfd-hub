<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property-read DocumentRevision|null $currentRevision */
final class ManualNode extends Model
{
    protected $table = 'policy_nodes';

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'metadata' => 'array'];

    /** @return BelongsTo<Manual, $this> */
    public function manual(): BelongsTo
    {
        return $this->belongsTo(Manual::class);
    }

    /** @return BelongsTo<Edition, $this> */
    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }

    /** @return BelongsTo<ManualNode, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<ManualNode, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<DocumentRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class, 'node_id');
    }

    /** @return BelongsTo<DocumentRevision, $this> */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'current_revision_id');
    }
}
