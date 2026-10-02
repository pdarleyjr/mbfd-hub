<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class DocumentRevision extends Model
{
    protected $table = 'policy_revisions';

    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array', 'revision_date' => 'date', 'published_at' => 'datetime'];

    protected static function booted(): void
    {
        self::updating(function (self $revision): void {
            foreach (['uuid', 'node_id', 'storage_path', 'source_path', 'source_filename', 'sha256', 'page_count', 'metadata', 'version_label', 'revision_date', 'revision_notes', 'uploaded_by'] as $field) {
                if ($revision->isDirty($field)) {
                    throw new LogicException('Document content is immutable. Create a new revision.');
                }
            }
        });
        self::deleting(fn () => throw new LogicException('Revision history cannot be deleted.'));
    }

    /** @return BelongsTo<ManualNode, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(ManualNode::class, 'node_id');
    }

    /** @return HasMany<PageMetadata, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(PageMetadata::class, 'revision_id')->orderBy('page');
    }
}
