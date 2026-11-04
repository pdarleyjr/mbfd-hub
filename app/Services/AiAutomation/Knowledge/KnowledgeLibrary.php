<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Knowledge;

use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion as Version;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The editable Knowledge Library: articles with draft, review, publish and withdraw steps. Only a published
 * version of a member-safe article that is in date can be retrieved for a reply. Everything an administrator
 * changes takes effect on the next retrieve, with no deploy.
 */
final class KnowledgeLibrary
{
    public const SEED_NAME = 'Repository starter guide';

    public const SEED_SOURCE_NOTE = 'Seeded from resources/ai/knowledge/mbfd-support-services-guidance.md';

    public const UPLOAD_MAX_BYTES = 204800;

    private ?KnowledgeRetriever $retriever = null;

    /** @param array<string, mixed> $data slug, title, body, topic_tags, member_safe, change_summary, source_note, effective_date, review_date */
    public function createArticle(array $data, User $editor): AiKnowledgeArticle
    {
        $slug = $this->validSlug((string) ($data['slug'] ?? ''));
        $content = $this->versionFields($data);

        return DB::transaction(function () use ($slug, $data, $content, $editor): AiKnowledgeArticle {
            if (AiKnowledgeArticle::query()->where('slug', $slug)->exists()) {
                throw new InvalidArgumentException("An article with the address \"{$slug}\" already exists.");
            }
            $article = AiKnowledgeArticle::query()->create([
                'slug' => $slug,
                'title' => $content['title'],
                'topic_tags' => self::tags($data['topic_tags'] ?? []),
                'member_safe' => (bool) ($data['member_safe'] ?? true),
                'always_include' => (bool) ($data['always_include'] ?? false),
            ]);
            $this->newVersion($article, 1, Version::STATUS_DRAFT, $content, $editor);

            return $article->fresh() ?? $article;
        });
    }

    /**
     * Edits the open draft, or starts a new draft (highest version + 1) from the live or latest version.
     * A published version is never edited in place.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(AiKnowledgeArticle $article, array $data, User $editor): Version
    {
        $content = $this->versionFields($data);

        return DB::transaction(function () use ($article, $data, $content, $editor): Version {
            $article = $this->lockArticle($article);
            $article->forceFill([
                'topic_tags' => self::tags($data['topic_tags'] ?? $article->topic_tags ?? []),
                'member_safe' => (bool) ($data['member_safe'] ?? $article->member_safe),
                'always_include' => (bool) ($data['always_include'] ?? $article->always_include),
            ])->save();

            $open = $this->openDraft($article);
            if ($open !== null) {
                $open->forceFill($content + ['status' => Version::STATUS_DRAFT, 'submitted_at' => null])->save();

                return $open;
            }

            $next = (int) Version::query()->where('article_id', $article->getKey())->max('version') + 1;

            return $this->newVersion($article, $next, Version::STATUS_DRAFT, $content, $editor);
        });
    }

    /** Changes only the article-level tags and member-safe flag (no new version). */
    public function updateSettings(AiKnowledgeArticle $article, mixed $tags, bool $memberSafe, ?bool $alwaysInclude = null): AiKnowledgeArticle
    {
        return DB::transaction(function () use ($article, $tags, $memberSafe, $alwaysInclude): AiKnowledgeArticle {
            $locked = $this->lockArticle($article);
            $locked->forceFill([
                'topic_tags' => self::tags($tags),
                'member_safe' => $memberSafe,
                'always_include' => $alwaysInclude ?? (bool) $locked->always_include,
            ])->save();

            return $locked;
        });
    }

    public function submitForReview(Version $version, User $editor): Version
    {
        return DB::transaction(function () use ($version): Version {
            $fresh = $this->lockVersion($version);
            if ($fresh->status !== Version::STATUS_DRAFT) {
                throw new InvalidArgumentException('Only a draft can be submitted for review.');
            }
            $fresh->forceFill(['status' => Version::STATUS_IN_REVIEW, 'submitted_at' => now()])->save();

            return $fresh;
        });
    }

    public function publish(Version $version, User $publisher): Version
    {
        return DB::transaction(function () use ($version, $publisher): Version {
            $fresh = $this->lockVersion($version);
            if (! $fresh->isOpen()) {
                throw new InvalidArgumentException('Only a draft or a version in review can be published.');
            }
            if (trim((string) $fresh->body) === '') {
                throw new InvalidArgumentException('An article cannot be published with an empty body.');
            }
            if ($fresh->review_date !== null && $fresh->review_date->toDateString() < $this->today()) {
                throw new InvalidArgumentException('The review date has already passed. Choose a future review date before publishing.');
            }

            $article = $this->lockArticle($fresh->article()->firstOrFail());
            Version::query()
                ->where('article_id', $article->getKey())
                ->where('status', Version::STATUS_PUBLISHED)
                ->whereKeyNot($fresh->getKey())
                ->get()
                ->each(fn (Version $prior) => $prior->forceFill(['status' => Version::STATUS_SUPERSEDED])->save());

            $fresh->forceFill([
                'status' => Version::STATUS_PUBLISHED,
                'published_by_user_id' => $publisher->getKey(),
                'published_by_name' => self::name($publisher),
                'published_at' => now(),
            ])->save();
            $article->forceFill(['published_version_id' => $fresh->getKey(), 'title' => $fresh->title])->save();

            return $fresh;
        });
    }

    /** Takes the live version out of use immediately. Returns false when nothing was published. */
    public function withdraw(AiKnowledgeArticle $article, User $editor): bool
    {
        return DB::transaction(function () use ($article): bool {
            $article = $this->lockArticle($article);
            if ($article->published_version_id === null) {
                return false;
            }
            $live = Version::query()->whereKey($article->published_version_id)->first();
            $live?->forceFill(['status' => Version::STATUS_WITHDRAWN, 'withdrawn_at' => now()])->save();
            $article->forceFill(['published_version_id' => null])->save();

            return true;
        });
    }

    /** The version being worked on: the open draft, else the live one, else the newest. */
    public function workingVersion(AiKnowledgeArticle $article): ?Version
    {
        return $this->openDraft($article)
            ?? ($article->published_version_id !== null ? Version::query()->find($article->published_version_id) : null)
            ?? Version::query()->where('article_id', $article->getKey())->orderByDesc('version')->first();
    }

    public function openDraft(AiKnowledgeArticle $article): ?Version
    {
        return Version::query()
            ->where('article_id', $article->getKey())
            ->whereIn('status', Version::OPEN_STATUSES)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Standing guidance: published, member-safe, in-date articles marked "Use in every AI answer" (at most three).
     *
     * @return list<Version>
     */
    public function standing(): array
    {
        $today = $this->today();

        return Version::query()
            ->with('article')
            ->where('status', Version::STATUS_PUBLISHED)
            ->whereIn('id', AiKnowledgeArticle::query()->where('member_safe', true)->where('always_include', true)->whereNotNull('published_version_id')->select('published_version_id'))
            ->where(fn ($q) => $q->whereNull('effective_date')->orWhereDate('effective_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('review_date')->orWhereDate('review_date', '>=', $today))
            ->orderBy('article_id')
            ->limit(3)
            ->get()
            ->all();
    }

    /**
     * Published, member-safe, in-date articles that best match the text; [] when nothing matches well enough.
     * A second article is only included when it scores close to the first, so the model prompt stays small.
     *
     * @return list<Version>
     */
    public function retrieve(string $text, int $limit = 2): array
    {
        return $this->versionsFrom($this->retriever()->rank($text), $limit);
    }

    /**
     * Like retrieve(), but only articles that match strongly (a whole tag or sample phrase, or three or more
     * words). Used for free text that is not a question: a weak match there is not worth a model call.
     *
     * @return list<Version>
     */
    public function retrieveStrong(string $text, int $limit = 2): array
    {
        return $this->versionsFrom(array_values(array_filter(
            $this->retriever()->rank($text),
            static fn (array $row): bool => $row['strong'],
        )), $limit);
    }

    /**
     * What the engine would find for a piece of text, and why. For the admin preview: the best candidates
     * (even ones that fall below the threshold), whether each would be used, and the words that matched.
     *
     * @return list<array{article_id: int, version_id: int, slug: string, title: string, score: int, retrieved: bool, strong: bool, matched_words: list<string>, synonym_words: list<string>, phrases: list<string>, fts_rank: float|null}>
     */
    public function previewRetrieval(string $text, int $limit = 5): array
    {
        return array_map(static fn (array $row): array => [
            'article_id' => (int) $row['article']->getKey(),
            'version_id' => (int) $row['version']->getKey(),
            'slug' => (string) $row['article']->slug,
            'title' => (string) $row['version']->title,
            'score' => $row['score'],
            'retrieved' => $row['retrieved'],
            'strong' => $row['strong'],
            'matched_words' => $row['matched'],
            'synonym_words' => $row['expanded'],
            'phrases' => $row['phrases'],
            'fts_rank' => $row['fts_rank'],
        ], array_slice($this->retriever()->rank($text), 0, max(1, $limit)));
    }

    /**
     * Does this version answer the phrases members are expected to use? Tests each sample phrase, each multi-word
     * tag and a title of two or more content words as if a member had typed it. `retrieved` means this article
     * clears the match threshold; `top` means no other published article scores higher, so the phrase would not
     * be answered from the wrong article. Run it before publishing and fix tags or sample phrases that fail.
     *
     * @return list<array{phrase: string, source: 'sample'|'title'|'tag', retrieved: bool, top: bool, score: int, competitor: ?string}>
     */
    public function selfRetrievalCheck(Version $version): array
    {
        $article = $version->article()->first();
        if ($article === null) {
            return [];
        }

        $phrases = [];
        foreach (KnowledgeRetriever::samplePhrases($version) as $phrase) {
            $phrases[$phrase] ??= 'sample';
        }
        if (count(KnowledgeRetriever::tokens((string) $version->title)) > 1) {
            $phrases[(string) $version->title] ??= 'title';
        }
        foreach ($article->tags() as $tag) {
            if (count(KnowledgeRetriever::tokens($tag)) > 1) {
                $phrases[$tag] ??= 'tag'; // tags are often shared between articles, so a rival here is only a hint
            }
        }

        $report = [];
        foreach ($phrases as $phrase => $source) {
            $phrase = (string) $phrase;
            $own = $this->retriever()->scoreOne($phrase, $version, $article);
            $rival = null;
            foreach ($this->retriever()->rank($phrase) as $row) {
                if ((int) $row['article']->getKey() !== (int) $article->getKey() && $row['retrieved']) {
                    $rival = $row;
                    break;
                }
            }
            $beaten = $rival !== null && $rival['score'] > $own['score'];
            $report[] = [
                'phrase' => $phrase,
                'source' => $source,
                'retrieved' => $own['retrieved'],
                'top' => $own['retrieved'] && ! $beaten,
                'score' => $own['score'],
                'competitor' => $beaten ? (string) $rival['article']->slug : null,
            ];
        }

        return $report;
    }

    /**
     * Imports the starter guide. Creates an article only when its slug does not exist yet, so an edited or
     * withdrawn article is never overwritten. Safe to run repeatedly.
     *
     * @return array{created: int, skipped: int}
     */
    public function importSeed(string $path): array
    {
        $result = ['created' => 0, 'skipped' => 0];
        if (! is_file($path) || ! is_readable($path)) {
            return $result;
        }

        foreach ($this->parseSeed((string) file_get_contents($path)) as $section) {
            if (AiKnowledgeArticle::query()->where('slug', $section['slug'])->exists()) {
                $result['skipped']++;

                continue;
            }
            DB::transaction(function () use ($section): void {
                $article = AiKnowledgeArticle::query()->create([
                    'slug' => $section['slug'],
                    'title' => $section['title'],
                    'topic_tags' => $section['tags'],
                    'member_safe' => true,
                    'always_include' => $section['always'],
                ]);
                $version = $this->newVersion($article, 1, Version::STATUS_PUBLISHED, [
                    'title' => $section['title'],
                    'body' => $section['body'],
                    'change_summary' => 'Starter guide',
                    'source_note' => self::SEED_SOURCE_NOTE,
                    'effective_date' => null,
                    'review_date' => $section['review'],
                    'sample_phrases' => self::phrases($section['samples']),
                    'published_by_name' => self::SEED_NAME,
                    'published_at' => now(),
                ], null, self::SEED_NAME);
                $article->forceFill(['published_version_id' => $version->getKey()])->save();
            });
            $result['created']++;
        }

        return $result;
    }

    /**
     * Text of an uploaded .md or .txt file for the body field. The file itself is not kept.
     *
     * @throws InvalidArgumentException
     */
    public function bodyFromUpload(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw new InvalidArgumentException('The file did not upload. Try again.');
        }
        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        if (! in_array($extension, ['md', 'txt'], true)) {
            throw new InvalidArgumentException('Only .md and .txt files can be uploaded.');
        }
        $size = $file->getSize();
        if ($size === false || $size > self::UPLOAD_MAX_BYTES) {
            throw new InvalidArgumentException('The file is larger than 200 KB.');
        }
        $contents = file_get_contents((string) $file->getRealPath());
        if ($contents === false || str_contains($contents, "\0") || ! mb_check_encoding($contents, 'UTF-8')) {
            throw new InvalidArgumentException('The file is not plain text.');
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents)));
        if ($text === '') {
            throw new InvalidArgumentException('The file is empty.');
        }

        return $text;
    }

    /** @return list<array{slug: string, title: string, tags: list<string>, samples: list<string>, review: ?string, always: bool, body: string}> */
    private function parseSeed(string $markdown): array
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $sections = [];
        $current = null;
        $inFence = false;

        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inFence = ! $inFence;
            }
            if (! $inFence && str_starts_with($line, '## ')) {
                if ($current !== null) {
                    $sections[] = $current;
                    $current = null;
                }
                $next = $lines[$i + 1] ?? '';
                if (preg_match('/^\s*<!--\s*kb\s+(.*?)\s*-->\s*$/', $next, $m) === 1) {
                    preg_match_all('/(\w+)="([^"]*)"/', $m[1], $pairs, PREG_SET_ORDER);
                    $attributes = [];
                    foreach ($pairs as $pair) {
                        $attributes[strtolower($pair[1])] = $pair[2];
                    }
                    $current = [
                        'title' => trim(substr($line, 3)),
                        'attributes' => $attributes,
                        'lines' => [],
                    ];
                    $i++;
                }

                continue;
            }
            if ($current !== null) {
                $current['lines'][] = $line;
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }

        $parsed = [];
        foreach ($sections as $section) {
            $slug = strtolower(trim($section['attributes']['slug'] ?? ''));
            $body = trim(implode("\n", $section['lines']));
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 120 || $body === '' || $section['title'] === '') {
                continue;
            }
            $review = $section['attributes']['review'] ?? null;
            $parsed[] = [
                'slug' => $slug,
                'title' => mb_substr($section['title'], 0, 200),
                'tags' => self::tags(explode(',', $section['attributes']['tags'] ?? '')),
                // samples="phrase one|phrase two": what a member might type to reach this article
                'samples' => array_values(array_filter(array_map('trim', explode('|', $section['attributes']['samples'] ?? '')))),
                'review' => is_string($review) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $review) === 1 ? $review : null,
                'always' => ($section['attributes']['always'] ?? '') === 'true',
                'body' => $body,
            ];
        }

        return $parsed;
    }

    private function retriever(): KnowledgeRetriever
    {
        return $this->retriever ??= new KnowledgeRetriever;
    }

    /**
     * @param  list<array{version: Version, score: int, retrieved: bool}>  $ranked
     * @return list<Version>
     */
    private function versionsFrom(array $ranked, int $limit): array
    {
        $retrieved = array_values(array_filter($ranked, static fn (array $row): bool => $row['retrieved']));
        if ($retrieved === [] || $limit < 1) {
            return [];
        }

        $top = $retrieved[0]['score'];
        $picked = array_filter($retrieved, static fn (array $row): bool => $top * 3 <= $row['score'] * 5);

        return array_map(static fn (array $row): Version => $row['version'], array_slice(array_values($picked), 0, $limit));
    }

    /** @return list<string> */
    private static function tags(mixed $raw): array
    {
        $list = is_string($raw) ? explode(',', $raw) : (array) $raw;
        $tags = [];
        foreach ($list as $tag) {
            $clean = mb_substr(trim(mb_strtolower((string) $tag)), 0, 40);
            if ($clean !== '') {
                $tags[$clean] = true;
            }
        }

        return array_map('strval', array_keys($tags));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, body: string, change_summary: ?string, source_note: ?string, effective_date: ?string, review_date: ?string, sample_phrases: ?string}
     */
    private function versionFields(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $body = trim(str_replace(["\r\n", "\r"], "\n", (string) ($data['body'] ?? '')));
        if ($title === '' || mb_strlen($title) > 200) {
            throw new InvalidArgumentException('Give the article a title of 200 characters or fewer.');
        }
        if ($body === '') {
            throw new InvalidArgumentException('The article body cannot be empty.');
        }

        $effective = self::dateOrNull($data['effective_date'] ?? null, 'effective date');
        $review = self::dateOrNull($data['review_date'] ?? null, 'review date');

        return [
            'title' => $title,
            'body' => $body,
            'change_summary' => self::limited($data['change_summary'] ?? null, 255),
            'source_note' => self::limited($data['source_note'] ?? null, 5000),
            'effective_date' => $effective,
            'review_date' => $review,
            'sample_phrases' => self::phrases($data['sample_phrases'] ?? null),
        ];
    }

    /** Up to 10 short phrases members might type to reach this article, stored as JSON (null when none). */
    private static function phrases(mixed $raw): ?string
    {
        $list = is_string($raw) ? (preg_split('/\R/', $raw) ?: []) : (array) $raw;
        $phrases = [];
        foreach ($list as $phrase) {
            $clean = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $phrase)), 0, 120);
            if ($clean !== '') {
                $phrases[mb_strtolower($clean)] = $clean;
            }
        }

        return $phrases === [] ? null : (string) json_encode(array_slice(array_values($phrases), 0, 10), JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function newVersion(AiKnowledgeArticle $article, int $number, string $status, array $content, ?User $editor, ?string $name = null): Version
    {
        return Version::query()->create($content + [
            'article_id' => $article->getKey(),
            'version' => $number,
            'status' => $status,
            'created_by_user_id' => $editor?->getKey(),
            'created_by_name' => $name ?? ($editor !== null ? self::name($editor) : 'Administrator'),
        ]);
    }

    private function lockArticle(AiKnowledgeArticle $article): AiKnowledgeArticle
    {
        return AiKnowledgeArticle::query()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockVersion(Version $version): Version
    {
        return Version::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
    }

    private function validSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 120) {
            throw new InvalidArgumentException('The address may use lower-case letters, numbers and single hyphens only (up to 120 characters).');
        }

        return $slug;
    }

    private function today(): string
    {
        return CarbonImmutable::now((string) config('ai_automation.timezone', 'America/New_York'))->toDateString();
    }

    private static function dateOrNull(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value)->toDateString();
        } catch (\Throwable) {
            throw new InvalidArgumentException("The {$label} is not a valid date.");
        }
    }

    private static function limited(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private static function name(User $user): string
    {
        $name = trim((string) ($user->display_name ?: $user->name));

        return mb_substr($name !== '' ? $name : 'Administrator', 0, 160);
    }
}
