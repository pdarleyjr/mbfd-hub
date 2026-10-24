<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * One member-facing library document. The original file is the authoritative copy; a PDF preview exists
 * only when the original is not itself a PDF. Bytes live on the private disk, never at a public URL.
 *
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $title
 * @property string $category
 * @property string|null $description
 * @property string $original_filename
 * @property string $original_mime
 * @property int $original_size
 * @property string $original_sha256
 * @property string $original_path
 * @property string|null $preview_path
 * @property string|null $preview_sha256
 * @property int|null $page_count
 * @property string|null $search_text
 * @property bool $published
 * @property int $sort
 * @property \Carbon\CarbonImmutable|null $published_at
 */
class ResourceDocument extends Model
{
    use SoftDeletes;

    public const PDF_MIME = 'application/pdf';

    /** Documents first shown to members within this many days carry the "New" badge. */
    public const NEW_BADGE_DAYS = 14;

    /** Wording fields whose changes are written to the history with their previous values. */
    private const AUDITED_DETAILS = ['title', 'category', 'description'];

    protected $fillable = [
        'slug', 'title', 'category', 'description',
        'original_filename', 'original_mime', 'original_size', 'original_sha256', 'original_path',
        'preview_path', 'preview_sha256', 'page_count', 'search_text',
        'published', 'sort', 'published_at',
    ];

    /** Extracted text can be megabytes; it is only ever read by the search query. */
    protected $hidden = ['search_text'];

    protected function casts(): array
    {
        return [
            'original_size' => 'integer',
            'page_count' => 'integer',
            'published' => 'boolean',
            'sort' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $document): void {
            $uuid = $document->getAttribute('uuid');
            if (! is_string($uuid) || $uuid === '') {
                $document->uuid = (string) Str::uuid();
            }
        });

        static::saving(function (self $document): void {
            if ($document->published && $document->published_at === null) {
                $document->published_at = now()->toImmutable();
            }
        });

        // Administrators change visibility, wording and order from the Admin panel; record who did it and what
        // it was before. Imports and replacements log their own events.
        static::updated(function (self $document): void {
            $document->recordAuditedChanges();
        });
    }

    /** @return HasMany<ResourceDocumentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ResourceDocumentEvent::class);
    }

    /** @return HasMany<ResourceDocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ResourceDocumentVersion::class);
    }

    /** The number of the file members get today: 1 until the first replacement. */
    public function currentVersionNumber(): int
    {
        return ((int) $this->versions()->max('version')) + 1;
    }

    /** Published recently enough to carry the "New" badge on the member page. */
    public function isNew(): bool
    {
        return $this->published_at !== null && $this->published_at->greaterThan(now()->subDays(self::NEW_BADGE_DAYS));
    }

    private function recordAuditedChanges(): void
    {
        $actor = Auth::id();
        $source = $actor === null ? 'system' : 'admin';

        if ($this->wasChanged('published')) {
            $this->events()->create([
                'event' => $this->published ? 'published' : 'unpublished',
                'actor_id' => $actor,
                'metadata' => ['source' => 'admin'],
            ]);
        }

        $before = [];
        $after = [];
        foreach (self::AUDITED_DETAILS as $field) {
            $old = (string) $this->getOriginal($field);
            $new = (string) $this->getAttribute($field);
            if ($old !== $new) {
                $before[$field] = $old;
                $after[$field] = $new;
            }
        }
        if ($before !== []) {
            $this->events()->create([
                'event' => 'details_changed',
                'actor_id' => $actor,
                'metadata' => ['source' => $source, 'before' => $before, 'after' => $after],
            ]);
        }

        if ($this->wasChanged('sort')) {
            $this->events()->create([
                'event' => 'reordered',
                'actor_id' => $actor,
                'metadata' => [
                    'source' => $source,
                    'before' => ['sort' => (int) $this->getOriginal('sort')],
                    'after' => ['sort' => (int) $this->sort],
                ],
            ]);
        }
    }

    /** @param  Builder<self>  $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    public function isPdf(): bool
    {
        return $this->original_mime === self::PDF_MIME;
    }

    /** The PDF members open in the browser: the original when it is a PDF, otherwise the generated preview. */
    public function previewDiskPath(): ?string
    {
        return $this->isPdf() ? $this->original_path : $this->preview_path;
    }

    public function hasPreview(): bool
    {
        return $this->previewDiskPath() !== null;
    }

    public function fileExtension(): string
    {
        return strtolower(pathinfo($this->original_filename, PATHINFO_EXTENSION));
    }

    public function fileTypeLabel(): string
    {
        return strtoupper($this->fileExtension() ?: 'file');
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
