<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

/**
 * Deterministic checks on model-written text. Anything doubtful returns null and the member gets the plain
 * acknowledgment instead. The validator judges the output only, never the model's own claims.
 */
final class ReplyValidator
{
    private const MIN_GROUNDED_SHARE = 0.7;

    /** Pleasantries and unsupported claims the owner ruled out. */
    private const FORBIDDEN = [
        'thank', 'thanks', 'sorry', 'apolog', 'understand your', 'frustrat', 'reach out', 'reaching out', 'happy to', 'glad to', 'hope this',
        'satisfaction', 'bringing this', 'please let us know', 'feel free',
        'ordered', 'we have ordered', 'will have it', 'ready for you', 'reviewed', 'approved', 'repaired', 'fixed', 'resolved', 'replaced',
        'on its way', 'delivered', 'in stock', 'guarantee', 'safe to', 'is safe', 'cleared for', 'in service', 'out of service',
        'sog', 'section 8', 'policy', 'procedure', 'manual', 'guideline', 'according to', 'per the',
        'as an ai', 'language model', 'i am an', "i'm an",
    ];

    public function validateModelAnswer(string $raw, string $guidance): ?string
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
        if (preg_match('/\d/u', $text) === 1 && ! $this->numbersComeFrom($text, $guidance)) {
            return null; // phone numbers, times and quantities must come from the approved guidance
        }

        $lower = mb_strtolower($text);
        foreach (self::FORBIDDEN as $phrase) {
            if (str_contains($lower, $phrase)) {
                return null;
            }
        }

        // Grounding, not just a deny-list: most of the answer's content words must come from the article.
        if (! $this->groundedIn($text, $guidance)) {
            return null;
        }

        return $text;
    }

    private function groundedIn(string $text, string $guidance): bool
    {
        $guidanceWords = array_flip($this->contentWords($guidance));
        $answerWords = array_values(array_unique($this->contentWords($text)));
        if ($answerWords === []) {
            return false;
        }

        $found = count(array_filter($answerWords, static fn (string $word): bool => isset($guidanceWords[$word])));

        return $found / count($answerWords) >= self::MIN_GROUNDED_SHARE;
    }

    /** @return list<string> lower-cased words of four or more letters, crude plural folded */
    private function contentWords(string $text): array
    {
        preg_match_all('/\p{L}{4,}/u', mb_strtolower($text), $matches);

        return array_map(static fn (string $word): string => (string) preg_replace('/(?:es|s)$/u', '', $word), $matches[0]);
    }

    private function sentenceCount(string $text): int
    {
        return count(array_filter(preg_split('/(?<=[.?])\s+/u', $text) ?: [], static fn (string $s): bool => trim($s) !== ''));
    }

    private function numbersComeFrom(string $text, string $guidance): bool
    {
        preg_match_all('/\d+/u', $text, $numbers);
        foreach ($numbers[0] as $number) {
            if (preg_match('/(?<!\d)'.preg_quote($number, '/').'(?!\d)/u', $guidance) !== 1) {
                return false;
            }
        }

        return true;
    }
}
