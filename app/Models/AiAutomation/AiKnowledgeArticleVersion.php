<?php

declare(strict_types=1);

namespace App\Models\AiAutomation;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One version of an article. Draft and in-review versions are editable. A published, superseded or withdrawn
 * version is frozen: the only change the model allows is the status step the library service performs
 * (published to superseded or withdrawn). Corrections are always a new version.
 *
 * @property \Illuminate\Support\Carbon|null $effective_date
 * @property \Illuminate\Support\Carbon|null $review_date
 */
class AiKnowledgeArticleVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const OPEN_STATUSES = [self::STATUS_DRAFT, self::STATUS_IN_REVIEW];

    public const FROZEN_STATUSES = [self::STATUS_PUBLISHED, self::STATUS_SUPERSEDED, self::STATUS_WITHDRAWN];

    /** The only columns that may change on a frozen version, and only along a status step. */
    private const STEP_COLUMNS = ['status', 'withdrawn_at', 'updated_at'];

    protected $table = 'ai_knowledge_article_versions';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(static function (self $version): void {
            $original = (string) $version->getOriginal('status');
            if (! in_array($original, self::FROZEN_STATUSES, true)) {
                return;
            }
            $allowedStep = $original === self::STATUS_PUBLISHED
                && in_array($version->status, [self::STATUS_SUPERSEDED, self::STATUS_WITHDRAWN], true);
            if (! $allowedStep || array_diff(array_keys($version->getDirty()), self::STEP_COLUMNS) !== []) {
                throw new LogicException('A published, superseded or withdrawn knowledge version cannot be changed. Start a new draft instead.');
            }
        });
        static::deleting(static function (self $version): void {
            if (in_array($version->status, self::FROZEN_STATUSES, true)) {
                throw new LogicException('A published, superseded or withdrawn knowledge version cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_date' => 'date',
            'review_date' => 'date',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** @return BelongsTo<AiKnowledgeArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeArticle::class, 'article_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
