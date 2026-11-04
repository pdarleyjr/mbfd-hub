<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

/**
 * Deterministic checks on model-written text. Anything doubtful returns null and the member gets the plain
 * acknowledgment instead. The validator judges the output only, never the model's own claims.
 *
 * Safety is a deny-list plus format rules (no promises, contacts, links, document names; numbers must come from
 * the guidance). Grounding is a vocabulary check: an accurate paraphrase reuses the guidance's and the member's
 * own words plus ordinary operational words, so it passes; an invented fact brings new nouns and fails.
 */
final class ReplyValidator
{
    /** At most this share of the answer's distinct content words may be unknown (not in the guidance, the question or the plain-words list). */
    private const MAX_UNKNOWN_SHARE = 0.25;

    /** And never more than this many unknown words, however long the answer is. */
    private const MAX_UNKNOWN_WORDS = 3;

    /** Pleasantries and unsupported claims the owner ruled out. Matched from a word start, so "sog" never hits "progress". */
    private const FORBIDDEN = [
        'thank', 'thanks', 'sorry', 'apolog', 'understand your', 'frustrat', 'reach out', 'reaching out', 'happy to', 'glad to', 'hope this',
        'satisfaction', 'bringing this', 'please let us know', 'feel free',
        'ordered', 'we have ordered', 'will have it', 'ready for you', 'reviewed', 'approved', 'repaired', 'fixed', 'resolved', 'replaced',
        'on its way', 'delivered', 'in stock', 'guarantee', 'safe to', 'is safe', 'cleared for', 'in service', 'out of service',
        'definitely', 'anytime', 'any time', 'no problem',
        'sog', 'section 8', 'policy', 'procedure', 'manual', 'guideline', 'according to', 'per the',
        'as an ai', 'language model', 'i am an', "i'm an",
    ];

    /**
     * Ordinary words that carry no fact of their own. They never count against an answer, which is what lets a
     * correct paraphrase ("look around the station" for "check the station") through.
     */
    private const PLAIN_WORDS = [
        'about', 'above', 'across', 'after', 'again', 'ahead', 'also', 'always', 'another', 'around', 'away', 'back', 'because', 'before', 'being',
        'below', 'between', 'both', 'bring', 'call', 'came', 'check', 'come', 'coming', 'confirm', 'could', 'does', 'done', 'each', 'either',
        'else', 'even', 'ever', 'every', 'extra', 'find', 'first', 'found', 'from', 'give', 'going', 'have', 'help', 'here', 'into', 'just',
        'keep', 'know', 'last', 'later', 'leave', 'left', 'like', 'look', 'looking', 'made', 'make', 'many', 'might', 'more', 'most', 'much',
        'must', 'need', 'needed', 'needs', 'next', 'once', 'only', 'other', 'others', 'over', 'please', 'put', 'really', 'right', 'same',
        'send', 'should', 'since', 'some', 'something', 'still', 'such', 'sure', 'take', 'tell', 'text', 'than', 'that', 'their', 'them',
        'then', 'there', 'these', 'they', 'this', 'those', 'through', 'under', 'until', 'upon', 'used', 'using', 'want', 'were', 'what', 'when',
        'where', 'whether', 'which', 'while', 'will', 'with', 'within', 'without', 'would', 'your', 'yours', 'instead', 'missing', 'available',
        'anything', 'nothing', 'someone', 'staff', 'member', 'members', 'item', 'items', 'report', 'reported', 'reporting', 'message',
        'contact', 'ask', 'know', 'okay', 'able', 'unable', 'already', 'though', 'doing', 'good', 'best', 'better', 'plain', 'simply', 'ready',
    ];

    /**
     * @param  string|null  $question  the member's own words; names they used (an item, a place) are allowed in the answer
     */
    public function validateModelAnswer(string $raw, string $guidance, ?string $question = null): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $text = trim($text, " \t\n\r\"'");

        if ($text === '' || str_contains(mb_strtoupper($text), ModelPrompt::NO_ANSWER)) {
            return null;
        }
        if (mb_strlen($text) > (int) config('ai_automation.reply.max_characters', 320)) {
            return null;
        }
        if ($this->sentenceCount($text) > (int) config('ai_automation.reply.max_sentences', 3)) {
            return null;
        }
        if (preg_match('/[!#*`>|]|^\s*[-•]|\n/u', $text) === 1) {
            return null; // exclamation, markdown or list formatting
        }
        if (preg_match('/https?:|www\.|@|\b[\w.-]+\.(com|gov|org|net)\b/iu', $text) === 1) {
            return null;
        }
        if (preg_match('/\d/u', $text) === 1 && ! $this->numbersComeFrom($text, $guidance.' '.(string) $question)) {
            return null; // phone numbers, times and quantities must come from the approved guidance or the member
        }

        $lower = mb_strtolower($text);
        foreach (self::FORBIDDEN as $phrase) {
            if (preg_match('/(?<![\p{L}])'.preg_quote($phrase, '/').'/u', $lower) === 1) {
                return null;
            }
        }

        if (! $this->groundedIn($text, $guidance, (string) $question)) {
            return null;
        }

        return $text;
    }

    /**
     * Words in the answer that appear in neither the guidance nor the member's question and are not plain words.
     * Used by the benchmark and the admin test screen to show why an answer was refused.
     *
     * @return list<string> stems
     */
    public function unknownWords(string $text, string $guidance, string $question = ''): array
    {
        $source = array_flip($this->stems($guidance.' '.$question));

        return array_values(array_filter(
            $this->contentStems($text),
            static fn (string $stem): bool => ! isset($source[$stem]) && ! self::prefixMatch($stem, $source),
        ));
    }

    private function groundedIn(string $text, string $guidance, string $question): bool
    {
        $content = $this->contentStems($text);
        if ($content === []) {
            return false; // nothing but plain words: it says nothing the guidance supports
        }

        $unknown = $this->unknownWords($text, $guidance, $question);
        $unknownCount = count($unknown);
        if ($unknownCount > self::MAX_UNKNOWN_WORDS || $unknownCount / count($content) > self::MAX_UNKNOWN_SHARE) {
            return false;
        }

        return $unknownCount < count($content); // at least one content word comes from the sources
    }

    /** @return list<string> distinct stems of the text's words of four or more letters, plain words removed */
    private function contentStems(string $text): array
    {
        $plain = array_flip(array_map(self::stem(...), self::PLAIN_WORDS));
        $stems = [];
        foreach ($this->stems($text) as $stem) {
            if (! isset($plain[$stem])) {
                $stems[$stem] = true;
            }
        }

        return array_map('strval', array_keys($stems));
    }

    /** @return list<string> */
    private function stems(string $text): array
    {
        preg_match_all('/\p{L}{4,}/u', mb_strtolower($text), $matches);

        return array_map(self::stem(...), $matches[0]);
    }

    /** Crude suffix fold: "replacements"/"replacing"/"replaced" -> "replac". Good enough to compare two short texts. */
    private static function stem(string $word): string
    {
        $word = mb_strtolower($word);
        foreach (['ments', 'ment', 'ations', 'ation', 'ings', 'ing', 'edly', 'ed', 'ies', 'es', 'ly', 's'] as $suffix) {
            if (mb_strlen($word) - mb_strlen($suffix) >= 4 && str_ends_with($word, $suffix)) {
                $word = mb_substr($word, 0, mb_strlen($word) - mb_strlen($suffix));
                break;
            }
        }

        return (string) preg_replace('/e$/u', '', $word);
    }

    /** @param  array<string, int>  $stems */
    private static function prefixMatch(string $stem, array $stems): bool
    {
        if (mb_strlen($stem) < 5) {
            return false;
        }
        $prefix = mb_substr($stem, 0, 5);
        foreach (array_keys($stems) as $candidate) {
            if (mb_strlen((string) $candidate) >= 5 && str_starts_with((string) $candidate, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function sentenceCount(string $text): int
    {
        return count(array_filter(preg_split('/(?<=[.?])\s+/u', $text) ?: [], static fn (string $s): bool => trim($s) !== ''));
    }

    private function numbersComeFrom(string $text, string $source): bool
    {
        preg_match_all('/\d+/u', $text, $numbers);
        foreach ($numbers[0] as $number) {
            if (preg_match('/(?<!\d)'.preg_quote($number, '/').'(?!\d)/u', $source) !== 1) {
                return false;
            }
        }

        return true;
    }
}
