<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Admin;

use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion as Version;
use Illuminate\Support\Str;

/**
 * Everything the Knowledge & Instructions screens do behind the scenes so an administrator never types a
 * slug, a tag list or Markdown: the article address, the matching words, the topic groups and the plain
 * status wording.
 */
final class KnowledgeAdminSupport
{
    /**
     * Topic groups for browsing. Each is a plain label and the words that put an article in it.
     *
     * @var array<string, array{label: string, words: list<string>}>
     */
    public const TOPICS = [
        'vehicles' => ['label' => 'Vehicles and checkouts', 'words' => ['vehicle', 'truck', 'checkout', 'apparatus', 'inventory', 'missing', 'spare', 'swap']],
        'station' => ['label' => 'Station requests and supplies', 'words' => ['station', 'supply', 'supplies', 'repair', 'maintenance', 'request']],
        'contacts' => ['label' => 'Who to contact', 'words' => ['contact', 'call', 'text', '300', 'officer', 'captain', 'air tech', 'staff']],
        'gear' => ['label' => 'Bunker gear and protective equipment', 'words' => ['bunker', 'turnout', 'gear', 'ppe', 'helmet', 'scba', 'mask', 'hood']],
        'timing' => ['label' => 'Weekends and after hours', 'words' => ['weekend', 'saturday', 'sunday', 'after hours', 'holiday', 'hours']],
        'batteries' => ['label' => 'Batteries and tools', 'words' => ['battery', 'batteries', 'charger', 'tool', 'radio', 'flashlight']],
    ];

    private const TAG_SKIP = ['the', 'and', 'for', 'with', 'from', 'that', 'this', 'your', 'about', 'how', 'what', 'who', 'when', 'where'];

    /** @return array<string, string> topic key => label */
    public static function topicOptions(): array
    {
        return array_map(static fn (array $topic): string => $topic['label'], self::TOPICS);
    }

    /** @return list<string> */
    public static function topicWords(string $key): array
    {
        return self::TOPICS[$key]['words'] ?? [];
    }

    /** @return list<string> topic labels an article belongs to, for the list */
    public static function topicsFor(AiKnowledgeArticle $article, ?string $title = null): array
    {
        $haystack = mb_strtolower(($title ?? (string) $article->title).' '.implode(' ', $article->tags()));
        $found = [];
        foreach (self::TOPICS as $topic) {
            foreach ($topic['words'] as $word) {
                if (str_contains($haystack, $word)) {
                    $found[] = $topic['label'];
                    break;
                }
            }
        }

        return $found;
    }

    /** Address for a new article: readable, unique and never shown to the administrator. */
    public static function slugFor(string $title): string
    {
        $base = Str::slug(Str::limit($title, 80, ''));
        $base = $base === '' ? 'article' : $base;
        $slug = $base;
        $n = 2;
        while (AiKnowledgeArticle::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * Matching words made from the title when the administrator gives none. The title already counts for
     * matching, so a short list is enough.
     *
     * @return list<string>
     */
    public static function tagsFor(string $title): array
    {
        $tags = [];
        $clean = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $title)));
        $words = array_values(array_filter(preg_split('/\s+/', $clean) ?: [], static fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, self::TAG_SKIP, true)));
        if (count($words) >= 2 && count($words) <= 4) {
            $tags[] = implode(' ', $words);
        }
        foreach ($words as $word) {
            $tags[] = $word;
        }

        return array_slice(array_values(array_unique($tags)), 0, 6);
    }

    /** Example messages as one per line for the editor (they are stored as a list). */
    public static function phrasesToLines(mixed $stored): string
    {
        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            $stored = is_array($decoded) ? $decoded : preg_split('/\R/', $stored);
        }

        return implode("\n", array_values(array_filter(array_map(static fn ($p): string => trim((string) $p), (array) $stored), static fn (string $p): bool => $p !== '')));
    }

    public static function statusKey(AiKnowledgeArticle $article): string
    {
        return $article->statusKey();
    }

    public static function statusLabel(AiKnowledgeArticle $article): string
    {
        $hasDraft = $article->latestVersion !== null && $article->latestVersion->isOpen();

        return match ($article->statusKey()) {
            'published' => $hasDraft ? 'Live, newer draft waiting' : 'Live',
            'in_review' => 'Draft, not live yet',
            'withdrawn' => 'Turned off',
            default => 'Draft, not live yet',
        };
    }

    public static function statusColor(AiKnowledgeArticle $article): string
    {
        return match ($article->statusKey()) {
            'published' => 'success',
            'withdrawn' => 'danger',
            default => 'gray',
        };
    }

    /** The words shown as the version label in the restore list. */
    public static function versionLabel(Version $version, string $timezone): string
    {
        $when = $version->created_at?->timezone($timezone)->format('M j, Y g:i A');
        $state = match ($version->status) {
            Version::STATUS_PUBLISHED => 'live now',
            Version::STATUS_SUPERSEDED => 'was live',
            Version::STATUS_WITHDRAWN => 'was turned off',
            default => 'draft',
        };

        return 'Saved '.$when.' by '.$version->created_by_name.' ('.$state.')';
    }
}
