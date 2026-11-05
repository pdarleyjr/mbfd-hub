<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\User;

/** Small text rules shared by the Latest activity readers: one excerpt length, one way to print a person. */
final class OperationsActivityText
{
    public const SUMMARY_LIMIT = 140;

    public const AUTOMATED_ACTOR = 'Support Services (automated)';

    /** First non-blank candidate, squished to one line and cut at the limit. Blank when every candidate is empty. */
    public static function excerpt(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $candidate));
            if ($text === '') {
                continue;
            }

            return mb_strlen($text) > self::SUMMARY_LIMIT ? rtrim(mb_substr($text, 0, self::SUMMARY_LIMIT - 1)).'…' : $text;
        }

        return '';
    }

    /** "Lead-in: text" as one excerpt, so the whole line stays inside the limit. */
    public static function lead(string $lead, string $text): string
    {
        $lead = trim($lead);
        $text = trim($text);
        if ($lead === '') {
            return self::excerpt($text);
        }
        if ($text === '') {
            return self::excerpt($lead);
        }

        return self::excerpt($lead.': '.$text);
    }

    public static function person(?User $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }
        $name = trim((string) ($user->display_name ?: $user->name));

        return $name === '' ? null : $name;
    }
}
