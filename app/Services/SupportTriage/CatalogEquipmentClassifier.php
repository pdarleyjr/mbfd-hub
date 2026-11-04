<?php

declare(strict_types=1);

namespace App\Services\SupportTriage;

use App\Models\AiAutomation\AiAutomationSetting;
use App\Models\SupportTriage\SupportEquipmentRule;
use App\Services\AiAutomation\TermMatcher;
use App\Services\SupportTriage\Contracts\EquipmentClassifier;

/**
 * Deterministic classification from the admin-managed equipment catalog (see Contracts\EquipmentClassifier for
 * the precedence). Never calls a model.
 *
 * Matching, for a checklist item:
 *  1. the whole item name equals a rule name or alias (plural-insensitive, parenthetical text ignored); a rule
 *     scoped to this checklist type beats an everywhere rule;
 *  2. otherwise the item name contains a rule's pattern alias (an alias ending in `*`, a word prefix such as
 *     "battery*"); protective-equipment rules at HIGH win, then the longest phrase, then scoped before everywhere;
 *  3. otherwise MEDIUM and unclassified, so staff look at it. Nothing unknown is ever quietly LOW.
 *
 * Free text (station and supply requests, messages) is matched by phrase, longest phrase first, never by broad
 * single words; very generic words are not matched in free text at all (AMBIGUOUS_TEXT_TERMS).
 *
 * The catalog is read once per container scope (one request or one queued job). The rule service calls forget()
 * after every write so a long-lived process never serves an old catalog.
 */
final class CatalogEquipmentClassifier implements EquipmentClassifier
{
    /** Phrases too generic to mean the equipment when they appear in a sentence ("engine hood", "ppe", "gear"). */
    private const AMBIGUOUS_TEXT_TERMS = ['scba', 'ppe', 'gear', 'hood', 'tic', 'cad', 'foam', 'line', 'ram', 'chain', 'light', 'siren', 'iron', 'passport', 'fan', 'cone', 'key'];

    /** A PPE mention that is only about cleaning or hand-offs is not a defect. */
    private const ROUTINE_WORDS = ['clean*', 'launder*', 'laundry', 'wash*', 'dropped off', 'drop off', 'dropping off', 'drop-off', 'picked up', 'pick up', 'picking up', 'extractor', 'returned'];

    private const DEFECT_WORDS = [
        'damage*', 'torn', 'tear', 'tears', 'rip*', 'burn*', 'melt*', 'broke*', 'break*', 'crack*', 'contaminat*', 'expos*', 'miss*', 'lost', 'stolen',
        'fail*', 'replace*', 'hole', 'holes', 'frayed', 'worn', 'degrad*', 'delaminat*', 'leak*', 'defect*', 'does not', 'doesnt', 'wont', 'not working',
        'smell*', 'stain*', 'shrunk', 'fit',
    ];

    private static int $generation = 0;

    /** @var list<CatalogEntry>|null */
    private ?array $entries = null;

    /** @var array<string, list<CatalogEntry>> */
    private array $exact = [];

    private int $loadedGeneration = -1;

    private ?int $policyVersion = null;

    /** Drops every memoized catalog (after a rule write). */
    public static function forget(): void
    {
        self::$generation++;
    }

    public function classifyItem(string $itemName, ?string $note = null, ?string $apparatusType = null, bool $significant = false, ?string $condition = null): EquipmentClassification
    {
        $entry = $this->matchItem($itemName, $apparatusType);
        $reasons = [];
        $level = EquipmentClassification::MEDIUM;
        $unclassified = false;

        if ($entry !== null) {
            $reasons[] = 'catalog_match';
            $level = $entry->level;
            if ($entry->category === 'scba_cylinder' && TermMatcher::any($itemName.' '.$note, ['leak*'])) {
                $reasons[] = 'cylinder_leak';
            }
        } else {
            $reasons[] = 'unclassified_fallback';
            $unclassified = true;
            if (TermMatcher::any($itemName, ['scba', 'scbas'])) {
                $reasons[] = 'unclear_scba';
            }
        }

        // A listed item's own name is never a hazard report ("Gaskets/Out of Service Tags" is a routine consumable);
        // only what the member wrote about it is. An item the catalog does not know is still checked by name.
        $hazard = HazardPhrases::find($entry === null ? $itemName : null, $note) !== null;
        if ($hazard) {
            $level = EquipmentClassification::HIGH;
            $reasons[] = 'hazard_override';
        }
        if ($significant) {
            $level = EquipmentClassification::HIGH;
            $reasons[] = 'significant_flag';
        }

        return $this->build($entry, $level, $reasons, $hazard, $unclassified, $unclassified && in_array('unclear_scba', $reasons, true));
    }

    public function classifyText(string $text, string $context): EquipmentClassification
    {
        $prepared = PhraseText::prepare($text);
        $matches = $prepared === '' ? [] : $this->textMatches(explode(' ', PhraseText::foldText($prepared)));
        $hazard = HazardPhrases::find($text) !== null;

        $best = null;
        $bestLevel = EquipmentClassification::LOW;
        $reasons = [];
        $routinePpe = false;
        foreach ($matches as $entry) {
            $level = $entry->level;
            if ($entry->isProtectiveHigh() && $this->routinePpeMention($prepared)) {
                $level = EquipmentClassification::LOW;
                $routinePpe = true;
            }
            if ($best === null || EquipmentClassification::rank($level) > EquipmentClassification::rank($bestLevel)) {
                $best = $entry;
                $bestLevel = $level;
            }
        }

        if ($best !== null) {
            $reasons[] = 'catalog_match';
            if ($routinePpe && $bestLevel === EquipmentClassification::LOW) {
                $reasons[] = 'ppe_routine_mention';
            }
            if (count($matches) > 1) {
                $reasons[] = 'multiple_matches';
            }
        }

        $unclassified = $best === null;
        $unclearScba = false;
        if ($unclassified) {
            $reasons[] = 'unclassified_fallback';
            $bestLevel = EquipmentClassification::MEDIUM;
            if (TermMatcher::any($text, ['scba', 'scbas'])) {
                $reasons[] = 'unclear_scba';
                $unclearScba = true;
            }
        }
        if ($hazard) {
            $bestLevel = EquipmentClassification::HIGH;
            $reasons[] = 'hazard_override';
        }

        return $this->build($best, $bestLevel, $reasons, $hazard, $unclassified, $unclearScba);
    }

    public function policyVersion(): int
    {
        $this->ensureCurrent();
        if ($this->policyVersion === null) {
            $value = AiAutomationSetting::query()->orderBy('id')->value('triage_policy_version');
            $this->policyVersion = is_numeric($value) ? max(1, (int) $value) : 1;
        }

        return $this->policyVersion;
    }

    // ------------------------------------------------------------------ result

    /**
     * @param  list<string>  $reasons
     */
    private function build(?CatalogEntry $entry, string $level, array $reasons, bool $hazard, bool $unclassified, bool $unclearScba): EquipmentClassification
    {
        $category = $entry->category ?? ($unclearScba ? 'scba_component' : 'other');
        $handler = $entry->handler ?? ($unclearScba ? 'air_tech' : 'support_services');

        return new EquipmentClassification(
            level: $level,
            category: $category,
            handler: $handler,
            ruleId: $entry?->id,
            ruleName: $entry?->name,
            reasons: array_values(array_unique($reasons)),
            memberResponse: $entry?->memberResponse,
            isPpe: $entry !== null && ($entry->isPpe || in_array($entry->category, ['ppe', 'scba_unit', 'scba_facepiece'], true)),
            isCritical: ($entry !== null && $entry->isCritical) || $level === EquipmentClassification::HIGH,
            hazard: $hazard,
            unclassified: $unclassified,
            contact300: $hazard ? 'always' : ($entry->contact300 ?? 'urgent'),
        );
    }

    // ----------------------------------------------------------------- catalog

    private function ensureCurrent(): void
    {
        if ($this->entries !== null && $this->loadedGeneration === self::$generation) {
            return;
        }

        $entries = [];
        $exact = [];
        foreach (SupportEquipmentRule::query()->where('active', true)->orderBy('id')->get() as $rule) {
            $entry = CatalogEntry::fromRule($rule);
            $entries[] = $entry;
            foreach ($entry->exactKeys as $key) {
                $exact[$key][] = $entry;
            }
        }

        $this->entries = $entries;
        $this->exact = $exact;
        $this->loadedGeneration = self::$generation;
        $this->policyVersion = null;
    }

    /** @return list<CatalogEntry> */
    private function entries(): array
    {
        $this->ensureCurrent();

        return $this->entries ?? [];
    }

    // ------------------------------------------------------------ item matching

    private function matchItem(string $itemName, ?string $apparatusType): ?CatalogEntry
    {
        $key = PhraseText::key($itemName);
        if ($key === '') {
            return null;
        }

        $entries = $this->entries();
        $exact = $this->pickByScope($this->exact[$key] ?? [], $apparatusType);
        if ($exact !== null) {
            return $exact;
        }

        $words = explode(' ', $key);
        $best = null;
        $bestRank = null;
        foreach ($entries as $entry) {
            if ($entry->scope !== null && $entry->scope !== $apparatusType) {
                continue;
            }
            foreach ($entry->patterns as $pattern) {
                if (self::patternAt($words, $pattern) === null) {
                    continue;
                }
                $rank = [
                    $entry->isProtectiveHigh() ? 1 : 0,
                    count($pattern),
                    array_sum(array_map('mb_strlen', $pattern)),
                    $entry->scope !== null ? 1 : 0,
                    EquipmentClassification::rank($entry->level),
                ];
                if ($bestRank === null || $rank > $bestRank) {
                    $best = $entry;
                    $bestRank = $rank;
                }
            }
        }

        return $best;
    }

    /**
     * @param  list<CatalogEntry>  $candidates
     */
    private function pickByScope(array $candidates, ?string $apparatusType): ?CatalogEntry
    {
        $scoped = [];
        $everywhere = [];
        foreach ($candidates as $candidate) {
            if ($candidate->scope === null) {
                $everywhere[] = $candidate;
            } elseif ($candidate->scope === $apparatusType) {
                $scoped[] = $candidate;
            }
        }
        $pool = $scoped !== [] ? $scoped : $everywhere;
        if ($pool === []) {
            return null;
        }
        usort($pool, static fn (CatalogEntry $a, CatalogEntry $b): int => [EquipmentClassification::rank($b->level), $a->id] <=> [EquipmentClassification::rank($a->level), $b->id]);

        return $pool[0];
    }

    // ------------------------------------------------------------ text matching

    /**
     * Rules whose phrases appear in the text, longest phrase first, each stretch of words used once.
     *
     * @param  list<string>  $words  folded words of the prepared text
     * @return list<CatalogEntry>
     */
    private function textMatches(array $words): array
    {
        $hits = [];
        foreach ($this->entries() as $entry) {
            if ($entry->scope !== null) {
                continue;
            }
            foreach ($entry->exactKeys as $key) {
                if (in_array($key, self::AMBIGUOUS_TEXT_TERMS, true)) {
                    continue;
                }
                $phrase = explode(' ', $key);
                $at = self::sequenceAt($words, $phrase);
                if ($at !== null) {
                    $hits[] = [$entry, $at, count($phrase), mb_strlen($key)];
                }
            }
            foreach ($entry->patterns as $pattern) {
                if (in_array(implode(' ', $pattern), self::AMBIGUOUS_TEXT_TERMS, true)) {
                    continue;
                }
                $at = self::patternAt($words, $pattern);
                if ($at !== null) {
                    $hits[] = [$entry, $at, count($pattern), array_sum(array_map('mb_strlen', $pattern))];
                }
            }
        }

        usort($hits, static fn (array $a, array $b): int => [$b[2], $b[3], EquipmentClassification::rank($b[0]->level), $a[0]->id] <=> [$a[2], $a[3], EquipmentClassification::rank($a[0]->level), $b[0]->id]);

        $used = [];
        $matched = [];
        foreach ($hits as [$entry, $start, $length]) {
            $span = range($start, $start + $length - 1);
            if (array_intersect($span, $used) !== []) {
                continue;
            }
            $used = [...$used, ...$span];
            $matched[$entry->id] = $entry;
        }

        return array_values($matched);
    }

    private function routinePpeMention(string $prepared): bool
    {
        return TermMatcher::any($prepared, self::ROUTINE_WORDS) && ! TermMatcher::any($prepared, self::DEFECT_WORDS);
    }

    // -------------------------------------------------------- sequence matching

    /**
     * Where $phrase appears in $words as whole words, or null.
     *
     * @param  list<string>  $words
     * @param  list<string>  $phrase
     */
    private static function sequenceAt(array $words, array $phrase): ?int
    {
        $count = count($phrase);
        for ($i = 0; $i + $count <= count($words); $i++) {
            if (array_slice($words, $i, $count) === $phrase) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Where $pattern (all words whole except the last, which is a word prefix) appears in $words, or null.
     *
     * @param  list<string>  $words
     * @param  list<string>  $pattern
     */
    private static function patternAt(array $words, array $pattern): ?int
    {
        $count = count($pattern);
        $prefix = $pattern[$count - 1];
        for ($i = 0; $i + $count <= count($words); $i++) {
            if ($count > 1 && array_slice($words, $i, $count - 1) !== array_slice($pattern, 0, $count - 1)) {
                continue;
            }
            if (str_starts_with($words[$i + $count - 1], $prefix)) {
                return $i;
            }
        }

        return null;
    }
}
