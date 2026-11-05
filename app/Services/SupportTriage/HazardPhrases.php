<?php

declare(strict_types=1);

namespace App\Services\SupportTriage;

/**
 * The few explicit phrases that mean "this is dangerous or the unit cannot do its job right now", whatever the
 * item is. Deliberately narrow: whole phrases only, never single everyday words like "light", "engine", "fire",
 * "monitor", "gear" or "pump". Text is matched after unit and place names are removed (PhraseText::prepare), a
 * phrase that is plainly negated ("no smoke") is ignored, and everyday uses of a word ("smoke detector",
 * "no smoking") are removed before matching.
 */
final class HazardPhrases
{
    /** @var list<string> prepared (lower case, no apostrophes) phrases */
    private const PHRASES = [
        'uncontrolled leak', 'wont stop leaking', 'will not stop leaking', 'cant stop leaking', 'leaking badly', 'leaking heavily',
        'sparking', 'throwing sparks', 'sparks coming', 'smoke', 'smoking',
        'burning smell', 'smells like burning', 'smell of burning', 'burning odor', 'burnt smell', 'burning plastic', 'burning rubber',
        'electrical shock', 'got shocked', 'shocked me', 'electrocuted',
        'exposed wire', 'exposed wires', 'bare wires', 'live wire', 'live wires',
        'gas leak', 'gas smell', 'smell gas', 'smells like gas', 'smell of gas', 'propane leak',
        'carbon monoxide', 'co alarm',
        'injury', 'injured', 'injuries',
        'out of service',
        'cant respond', 'cannot respond', 'unable to respond',
        'unsafe', 'not safe',
        'flooding', 'flooded', 'burst pipe', 'pipe burst',
        'ceiling collapse', 'ceiling fell', 'roof collapse', 'structural damage',
    ];

    /** Everyday uses removed before matching, so "smoke detector" or "no smoking" never raise the alarm. */
    private const EVERYDAY = [
        '/\bsmoke\s+(?:detector|detectors|alarm|alarms|ejector|ejectors|fan|fans|machine|sign|signs|curtain|curtains)\b/u',
        '/\bno\s+smoking\b/u',
        '/\bsmoking\s+(?:area|section|policy|sign)\b/u',
    ];

    /** Words that, just before a phrase, mean it did not happen. */
    private const NEGATORS = ['no', 'not', 'without', 'zero', 'never', 'nothing', 'nobody'];

    /** Words that may sit between a negator and the phrase ("no visible smoke", "not any injury"). */
    private const FILLERS = ['any', 'a', 'an', 'the', 'visible', 'real', 'apparent', 'obvious', 'sign', 'signs', 'of', 'major', 'serious'];

    /** The first hazard phrase in the text, or null. */
    public static function find(?string ...$texts): ?string
    {
        foreach ($texts as $text) {
            $prepared = PhraseText::prepare($text);
            if ($prepared === '') {
                continue;
            }
            foreach (self::EVERYDAY as $pattern) {
                $prepared = (string) preg_replace($pattern, ' ', $prepared);
            }
            $found = self::match(' '.trim((string) preg_replace('/\s+/u', ' ', $prepared)).' ');
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function match(string $padded): ?string
    {
        foreach (self::PHRASES as $phrase) {
            $offset = 0;
            while (($at = strpos($padded, ' '.$phrase.' ', $offset)) !== false) {
                if (! self::negated(substr($padded, 0, $at))) {
                    return $phrase;
                }
                $offset = $at + 1;
            }
        }

        return null;
    }

    private static function negated(string $before): bool
    {
        $words = array_reverse(array_slice(PhraseText::words(trim($before)), -4));
        foreach ($words as $word) {
            if (in_array($word, self::FILLERS, true)) {
                continue;
            }

            return in_array($word, self::NEGATORS, true);
        }

        return false;
    }
}
