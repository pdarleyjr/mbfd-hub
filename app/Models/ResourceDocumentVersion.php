<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A file set a library document had before it was replaced. Append-only: the bytes stay on the private
 * disk at these paths and the row is never edited.
 *
 * @property int $id
 * @property int $resource_document_id
 * @property int $version
 * @property string $original_filename
 * @property string $original_mime
 * @property int $original_size
 * @property string $original_sha256
 * @property string $original_path
 * @property string|null $preview_path
 * @property string|null $preview_sha256
 * @property int|null $page_count
 * @property int|null $replaced_by
 * @property \Carbon\CarbonImmutable $replaced_at
 */
class ResourceDocumentVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'resource_document_id', 'version',
        'original_filename', 'original_mime', 'original_size', 'original_sha256', 'original_path',
        'preview_path', 'preview_sha256', 'page_count', 'replaced_by', 'replaced_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'original_size' => 'integer',
            'page_count' => 'integer',
            'replaced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Resource document versions are append-only.'));
        static::deleting(fn () => throw new LogicException('Resource document versions are append-only.'));
    }

    /** @return BelongsTo<ResourceDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ResourceDocument::class, 'resource_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_by');
    }

    public function fileTypeLabel(): string
    {
        return strtoupper(pathinfo($this->original_filename, PATHINFO_EXTENSION) ?: 'file');
    }

    public function readableSize(): string
    {
        $bytes = max(0, $this->original_size);
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 1).' MB';
        }

        return number_format(max(1, (int) round($bytes / 1024))).' KB';
    }
}
