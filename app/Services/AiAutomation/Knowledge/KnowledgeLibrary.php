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

    private const MIN_SCORE = 4;

    private const MIN_DISTINCT_MATCHES = 2;

    private const STOP_WORDS = [
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'your', 'yours', 'can', 'could', 'should', 'would', 'will', 'shall', 'has', 'have', 'had',
        'was', 'were', 'been', 'being', 'does', 'did', 'doing', 'done', 'this', 'that', 'these', 'those', 'there', 'their', 'them', 'they', 'then',
        'than', 'with', 'without', 'from', 'into', 'onto', 'about', 'what', 'when', 'where', 'which', 'who', 'whom', 'whose', 'why', 'how', 'any',
        'all', 'some', 'out', 'off', 'our', 'ours', 'its', 'his', 'her', 'she', 'him', 'let', 'get', 'got', 'need', 'needs', 'want', 'wants', 'like',
        'please', 'thanks', 'thank', 'hello', 'hey', 'just', 'also', 'very', 'much', 'many', 'more', 'most', 'other', 'over', 'under', 'again',
        'know', 'tell', 'make', 'made', 'use', 'used', 'using', 'one', 'two', 'new', 'old', 'time', 'help', 'someone', 'anyone', 'person', 'thing',
        'things', 'able', 'way', 'who', 'is', 'am', 'be', 'do', 'it', 'of', 'to', 'in', 'on', 'at', 'by', 'or', 'as', 'an', 'if', 'so', 'we', 'me', 'my',
    ];

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
    public function updateSettings(AiKnowledgeArticle $article, mixed $tags, bool $memberSafe): AiKnowledgeArticle
    {
        return DB::transaction(function () use ($article, $tags, $memberSafe): AiKnowledgeArticle {
            $locked = $this->lockArticle($article);
            $locked->forceFill(['topic_tags' => self::tags($tags), 'member_safe' => $memberSafe])->save();

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
     * Published guidance relevant to the text, best first. Unrelated text returns an empty list.
     *
     * @return list<Version>
     */
    public function retrieve(string $text, int $limit = 2): array
    {
        $queryTokens = self::tokens($text);
        if ($queryTokens === [] || $limit < 1) {
            return [];
        }
        $lowerText = ' '.implode(' ', $queryTokens).' ';
        $today = $this->today();

        $candidates = Version::query()
            ->with('article')
            ->where('status', Version::STATUS_PUBLISHED)
            ->whereIn('id', AiKnowledgeArticle::query()->where('member_safe', true)->whereNotNull('published_version_id')->select('published_version_id'))
            ->where(fn ($q) => $q->whereNull('effective_date')->orWhereDate('effective_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('review_date')->orWhereDate('review_date', '>=', $today))
            ->get();

        $scored = [];
        foreach ($candidates as $version) {
            $article = $version->article;
            if ($article === null || (int) $article->published_version_id !== (int) $version->getKey()) {
                continue;
            }
            $score = $this->score($queryTokens, $lowerText, $version, $article);
            if ($score !== null) {
                $scored[] = [$score, (int) $article->getKey(), $version];
            }
        }

        usort($scored, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_map(static fn (array $row): Version => $row[2], array_slice($scored, 0, $limit));
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
                ]);
                $version = $this->newVersion($article, 1, Version::STATUS_PUBLISHED, [
                    'title' => $section['title'],
                    'body' => $section['body'],
                    'change_summary' => 'Starter guide',
                    'source_note' => self::SEED_SOURCE_NOTE,
                    'effective_date' => null,
                    'review_date' => $section['review'],
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

    /** @return list<array{slug: string, title: string, tags: list<string>, review: ?string, body: string}> */
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
                'review' => is_string($review) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $review) === 1 ? $review : null,
                'body' => $body,
            ];
        }

        return $parsed;
    }

    /**
     * @param  list<string>  $queryTokens
     */
    private function score(array $queryTokens, string $lowerText, Version $version, AiKnowledgeArticle $article): ?int
    {
        $titleTokens = array_flip(self::tokens((string) $version->title));
        $tagTokens = array_flip(self::tokens(implode(' ', $article->tags())));
        $bodyTokens = array_flip(self::tokens((string) $version->body));

        $score = 0;
        $matched = 0;
        foreach (array_unique($queryTokens) as $token) {
            $points = 0;
            if (isset($titleTokens[$token])) {
                $points += 3;
            }
            if (isset($tagTokens[$token])) {
                $points += 3;
            }
            if (isset($bodyTokens[$token])) {
                $points += 1;
            }
            if ($points > 0) {
                $matched++;
                $score += $points;
            }
        }

        // A whole multi-word tag phrase ("kitty litter") is a strong signal on its own. Single-word tags
        // ("saturday", "who") are too common to carry a match without a second overlapping word.
        $phraseHit = false;
        foreach ($article->tags() as $tag) {
            $words = self::tokens($tag);
            $phrase = implode(' ', $words);
            if ($phrase !== '' && str_contains($lowerText, ' '.$phrase.' ')) {
                $score += 6;
                $phraseHit = $phraseHit || count($words) > 1;
            }
        }

        if ($score < self::MIN_SCORE || ($matched < self::MIN_DISTINCT_MATCHES && ! $phraseHit)) {
            return null;
        }

        return $score;
    }

    /** @return list<string> lower-case words, plural "s" folded, stop words and very short words removed */
    private static function tokens(string $text): array
    {
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (strlen($word) < 3 || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }
            if (strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss')) {
                $word = substr($word, 0, -1);
            }
            $out[] = $word;
        }

        return $out;
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
     * @return array{title: string, body: string, change_summary: ?string, source_note: ?string, effective_date: ?string, review_date: ?string}
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
        ];
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
