<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Knowledge;

use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion as Version;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Finds the published, member-safe, in-date Knowledge Library articles that answer a piece of text. Everything
 * stays in the Hub database: a word scorer (title, tags, body, whole tag phrases and optional sample phrases),
 * synonym groups from config and from the equipment catalog (so "multigas" finds an article that says "gas
 * meter"), and on PostgreSQL a full-text rank (ts_rank_cd) added to the score. SQLite (tests) uses the word scorer.
 *
 * Scores are in half points: a direct word hit in the title or a tag is 6, in the body 2; a synonym hit is half of
 * that; a whole multi-word tag or sample phrase found in the text adds 12.
 */
final class KnowledgeRetriever
{
    public const MIN_SCORE = 8;

    public const STRONG_SCORE = 20;

    private const MIN_DISTINCT_MATCHES = 2;

    private const MAX_EXPANSION_TOKENS = 40;

    private const CATALOG_CACHE_SECONDS = 60;

    private const STOP_WORDS = [
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'your', 'yours', 'can', 'could', 'should', 'would', 'will', 'shall', 'has', 'have', 'had',
        'was', 'were', 'been', 'being', 'does', 'did', 'doing', 'done', 'this', 'that', 'these', 'those', 'there', 'their', 'them', 'they', 'then',
        'than', 'with', 'without', 'from', 'into', 'onto', 'about', 'what', 'when', 'where', 'which', 'who', 'whom', 'whose', 'why', 'how', 'any',
        'all', 'some', 'out', 'off', 'our', 'ours', 'its', 'his', 'her', 'she', 'him', 'let', 'get', 'got', 'need', 'needs', 'want', 'wants', 'like',
        'please', 'thanks', 'thank', 'hello', 'hey', 'just', 'also', 'very', 'much', 'many', 'more', 'most', 'other', 'over', 'under', 'again',
        'know', 'tell', 'make', 'made', 'use', 'used', 'using', 'one', 'two', 'new', 'old', 'time', 'help', 'someone', 'anyone', 'person', 'thing',
        'things', 'able', 'way', 'is', 'am', 'be', 'do', 'it', 'of', 'to', 'in', 'on', 'at', 'by', 'or', 'as', 'an', 'if', 'so', 'we', 'me', 'my',
    ];

    /** @var array{at: int, groups: list<list<string>>}|null */
    private ?array $catalogGroups = null;

    /**
     * Ranked candidates, best first. Each entry says how the text matched.
     *
     * @return list<array{version: Version, article: AiKnowledgeArticle, score: int, retrieved: bool, strong: bool, matched: list<string>, expanded: list<string>, phrases: list<string>, fts_rank: float|null}>
     */
    public function rank(string $text): array
    {
        $queryTokens = array_values(array_unique(self::tokens($text)));
        if ($queryTokens === []) {
            return [];
        }
        $candidates = $this->candidates();
        $expansion = $this->expansion($text, $queryTokens);
        $fts = $this->ftsRanks($candidates, [...$queryTokens, ...$expansion]);

        $ranked = [];
        foreach ($candidates as $version) {
            $article = $version->article;
            if ($article === null || (int) $article->published_version_id !== (int) $version->getKey()) {
                continue;
            }
            $result = $this->evaluate($queryTokens, $expansion, $text, $version, $article, $fts[(int) $version->getKey()] ?? null);
            if ($result['score'] > 0) {
                $ranked[] = $result;
            }
        }

        usort($ranked, static fn (array $a, array $b): int => [$b['score'], $a['article']->getKey()] <=> [$a['score'], $b['article']->getKey()]);

        return $ranked;
    }

    /**
     * The same scoring for one specific version (published or not), used to check that an article answers its own
     * sample phrases before it is published.
     *
     * @return array{score: int, retrieved: bool, strong: bool, matched: list<string>, expanded: list<string>, phrases: list<string>}
     */
    public function scoreOne(string $text, Version $version, AiKnowledgeArticle $article): array
    {
        $queryTokens = array_values(array_unique(self::tokens($text)));
        if ($queryTokens === []) {
            return ['score' => 0, 'retrieved' => false, 'strong' => false, 'matched' => [], 'expanded' => [], 'phrases' => []];
        }
        $result = $this->evaluate($queryTokens, $this->expansion($text, $queryTokens), $text, $version, $article, null);
        unset($result['version'], $result['article'], $result['fts_rank']);

        return $result;
    }

    /** @return list<string> lower-case words, plural "s" folded, stop words and very short words removed */
    public static function tokens(string $text): array
    {
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if ((strlen($word) < 3 && ! ctype_digit($word)) || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }
            if (strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss')) {
                $word = substr($word, 0, -1);
            }
            $out[] = $word;
        }

        return $out;
    }

    /** @return list<string> the sample phrases stored on a version (newest column; absent on old rows) */
    public static function samplePhrases(Version $version): array
    {
        $raw = $version->getAttribute('sample_phrases');
        $list = is_string($raw) ? json_decode($raw, true) : $raw;
        $phrases = [];
        foreach (is_array($list) ? $list : [] as $phrase) {
            $clean = trim((string) $phrase);
            if ($clean !== '') {
                $phrases[] = $clean;
            }
        }

        return $phrases;
    }

    /**
     * @param  list<string>  $queryTokens
     * @param  list<string>  $expansion
     * @return array{version: Version, article: AiKnowledgeArticle, score: int, retrieved: bool, strong: bool, matched: list<string>, expanded: list<string>, phrases: list<string>, fts_rank: float|null}
     */
    private function evaluate(array $queryTokens, array $expansion, string $text, Version $version, AiKnowledgeArticle $article, ?float $ftsRank): array
    {
        $samples = self::samplePhrases($version);
        $titleTokens = array_flip(self::tokens((string) $version->title));
        $tagTokens = array_flip(self::tokens(implode(' ', [...$article->tags(), ...$samples])));
        $bodyTokens = array_flip(self::tokens((string) $version->body));

        $score = 0;
        $matched = [];
        foreach ($queryTokens as $token) {
            $points = (isset($titleTokens[$token]) ? 6 : 0) + (isset($tagTokens[$token]) ? 6 : 0) + (isset($bodyTokens[$token]) ? 2 : 0);
            if ($points > 0) {
                $matched[] = $token;
                $score += $points;
            }
        }
        $expanded = [];
        foreach ($expansion as $token) {
            if (in_array($token, $queryTokens, true)) {
                continue;
            }
            $points = (isset($titleTokens[$token]) ? 3 : 0) + (isset($tagTokens[$token]) ? 3 : 0) + (isset($bodyTokens[$token]) ? 1 : 0);
            if ($points > 0) {
                $expanded[] = $token;
                $score += $points;
            }
        }

        // A whole multi-word tag or sample phrase ("kitty litter") is a strong signal on its own. Single-word tags
        // ("saturday", "who") are too common to carry a match without a second overlapping word.
        $lowerText = ' '.implode(' ', self::tokens($text)).' ';
        $phraseHit = false;
        $phrases = [];
        foreach ([...$article->tags(), ...$samples] as $tag) {
            $words = self::tokens($tag);
            $phrase = implode(' ', $words);
            if ($phrase !== '' && str_contains($lowerText, ' '.$phrase.' ')) {
                $score += 12;
                $phrases[] = $tag;
                $phraseHit = $phraseHit || count($words) > 1;
            }
        }

        $distinct = count($matched) + (int) floor(count($expanded) / 2);
        if ($ftsRank !== null && $ftsRank > 0) {
            $score += (int) round(min(1.0, $ftsRank) * 8);
            $distinct += $ftsRank >= 0.2 ? 1 : 0;
        }

        $retrieved = $score >= self::MIN_SCORE && ($distinct >= self::MIN_DISTINCT_MATCHES || $phraseHit);
        $strong = $retrieved && $score >= self::STRONG_SCORE && ($phraseHit || count($matched) >= 3);

        return [
            'version' => $version,
            'article' => $article,
            'score' => $score,
            'retrieved' => $retrieved,
            'strong' => $strong,
            'matched' => $matched,
            'expanded' => $expanded,
            'phrases' => $phrases,
            'fts_rank' => $ftsRank,
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Version> */
    private function candidates(): \Illuminate\Database\Eloquent\Collection
    {
        $today = CarbonImmutable::now((string) config('ai_automation.timezone', 'America/New_York'))->toDateString();

        return Version::query()
            ->with('article')
            ->where('status', Version::STATUS_PUBLISHED)
            ->whereIn('id', AiKnowledgeArticle::query()->where('member_safe', true)->whereNotNull('published_version_id')->select('published_version_id'))
            ->where(fn ($q) => $q->whereNull('effective_date')->orWhereDate('effective_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('review_date')->orWhereDate('review_date', '>=', $today))
            ->get();
    }

    /**
     * Words from synonym groups that share a phrase with the text. Config groups first, then the catalog's.
     *
     * @param  list<string>  $queryTokens
     * @return list<string>
     */
    private function expansion(string $text, array $queryTokens): array
    {
        $normalized = ' '.trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($text))).' ';
        $groups = [...(array) config('ai_automation.retrieval.synonyms', []), ...$this->catalogGroups()];

        $extra = [];
        foreach ($groups as $group) {
            $phrases = array_values(array_filter(array_map(
                static fn ($phrase): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $phrase))),
                (array) $group,
            ), static fn (string $phrase): bool => $phrase !== ''));
            $hit = false;
            foreach ($phrases as $phrase) {
                if (str_contains($normalized, ' '.$phrase.' ') || str_contains($normalized, ' '.$phrase.'s ')) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                continue;
            }
            foreach ($phrases as $phrase) {
                foreach (self::tokens($phrase) as $token) {
                    if (! in_array($token, $queryTokens, true)) {
                        $extra[$token] = true;
                    }
                }
            }
            if (count($extra) >= self::MAX_EXPANSION_TOKENS) {
                break;
            }
        }

        return array_slice(array_map('strval', array_keys($extra)), 0, self::MAX_EXPANSION_TOKENS);
    }

    /**
     * One group per active catalog rule: its name, its aliases and its category's plain label. Read at most once
     * a minute, so a long-running queue worker still sees an edit soon.
     *
     * @return list<list<string>>
     */
    private function catalogGroups(): array
    {
        if ($this->catalogGroups !== null && time() - $this->catalogGroups['at'] < self::CATALOG_CACHE_SECONDS) {
            return $this->catalogGroups['groups'];
        }

        $groups = [];
        try {
            if (Schema::hasTable('support_equipment_rules')) {
                $labels = (array) config('ai_automation.retrieval.category_labels', []);
                foreach (DB::table('support_equipment_rules')->where('active', true)->limit(1000)->get(['name', 'aliases', 'category']) as $rule) {
                    $aliases = is_string($rule->aliases) ? json_decode($rule->aliases, true) : [];
                    $group = array_values(array_filter([(string) $rule->name, ...array_map('strval', is_array($aliases) ? $aliases : [])]));
                    if (isset($labels[$rule->category])) {
                        $group[] = (string) $labels[$rule->category];
                    }
                    if (count($group) > 1) {
                        $groups[] = $group;
                    }
                }
            }
        } catch (Throwable) {
            $groups = []; // retrieval still works from the config synonyms
        }
        $this->catalogGroups = ['at' => time(), 'groups' => $groups];

        return $groups;
    }

    /**
     * PostgreSQL full-text rank per version id. Empty on any other driver, and on any error (the word scorer
     * still runs).
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Version>  $candidates
     * @param  list<string>  $tokens
     * @return array<int, float>
     */
    private function ftsRanks($candidates, array $tokens): array
    {
        if ($candidates->isEmpty() || $tokens === [] || DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        $terms = array_values(array_unique(array_filter($tokens, static fn (string $t): bool => preg_match('/^[a-z0-9]{2,40}$/', $t) === 1)));
        if ($terms === []) {
            return [];
        }
        $ids = $candidates->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        try {
            // In a savepoint: a failed statement must not abort a surrounding transaction (PostgreSQL rule).
            $rows = DB::transaction(static fn (): array => DB::select(
                "SELECT id, ts_rank_cd(to_tsvector('english', coalesce(title, '') || ' ' || coalesce(body, '')), to_tsquery('english', ?)) AS rank
                 FROM ai_knowledge_article_versions WHERE id IN ({$placeholders})",
                [implode(' | ', $terms), ...$ids],
            ));
        } catch (Throwable) {
            return [];
        }

        $ranks = [];
        foreach ($rows as $row) {
            $ranks[(int) $row->id] = (float) $row->rank;
        }

        return $ranks;
    }
}
