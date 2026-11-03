<?php

declare(strict_types=1);

namespace App\Models\AiAutomation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Knowledge Library article. The article row holds identity (slug), tags and the member-safe flag; the
 * words live in versions. Only the version named by published_version_id can ever reach a reply.
 */
class AiKnowledgeArticle extends Model
{
    protected $table = 'ai_knowledge_articles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'topic_tags' => 'array',
            'member_safe' => 'boolean',
            'published_version_id' => 'integer',
        ];
    }

    /** @return HasMany<AiKnowledgeArticleVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AiKnowledgeArticleVersion::class, 'article_id')->orderByDesc('version');
    }

    /** @return BelongsTo<AiKnowledgeArticleVersion, $this> */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeArticleVersion::class, 'published_version_id');
    }

    /** @return HasOne<AiKnowledgeArticleVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(AiKnowledgeArticleVersion::class, 'article_id')->ofMany('version', 'max');
    }

    /** @return list<string> */
    public function tags(): array
    {
        $raw = $this->getAttribute('topic_tags');
        $tags = [];
        foreach (is_array($raw) ? $raw : [] as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /** published | draft | in_review | withdrawn: what an administrator would call the article's state. */
    public function statusKey(): string
    {
        if ($this->published_version_id !== null) {
            return AiKnowledgeArticleVersion::STATUS_PUBLISHED;
        }

        return match ($this->latestVersion?->status) {
            AiKnowledgeArticleVersion::STATUS_IN_REVIEW => 'in_review',
            AiKnowledgeArticleVersion::STATUS_WITHDRAWN, AiKnowledgeArticleVersion::STATUS_SUPERSEDED => 'withdrawn',
            default => 'draft',
        };
    }
}
