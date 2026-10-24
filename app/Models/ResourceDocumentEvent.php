<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of imports, replacements and visibility changes for a library document.
 *
 * @property int $id
 * @property int $resource_document_id
 * @property string $event imported|replaced|published|unpublished
 * @property int|null $actor_id
 * @property array<string, mixed>|null $metadata
 * @property \Carbon\CarbonImmutable $created_at
 */
class ResourceDocumentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['resource_document_id', 'event', 'actor_id', 'metadata'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Resource document events are append-only.'));
        static::deleting(fn () => throw new LogicException('Resource document events are append-only.'));
    }

    /** @return BelongsTo<ResourceDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ResourceDocument::class, 'resource_document_id');
    }
}
