<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * One rule for the reason an admin gives when a member's request is denied or
 * cancelled. The reason is what the member reads, so it must say something.
 */
final class DecisionReason
{
    public const MIN_LENGTH = 10;

    public const MAX_LENGTH = 1000;

    /**
     * @param  string  $field  validation key the message is attached to (the form field name)
     * @param  string  $verb  past tense shown in the message, e.g. "denied" or "cancelled"
     */
    public static function require(?string $reason, string $field, string $verb): string
    {
        $trimmed = trim((string) $reason);
        $length = mb_strlen($trimmed);

        if ($length < self::MIN_LENGTH) {
            throw ValidationException::withMessages([
                $field => 'Tell the member why this request was '.$verb.' (at least '.self::MIN_LENGTH.' characters).',
            ]);
        }
        if ($length > self::MAX_LENGTH) {
            throw ValidationException::withMessages([
                $field => 'Keep the reason to '.self::MAX_LENGTH.' characters or fewer.',
            ]);
        }

        return $trimmed;
    }

    /** "Denied: <reason>" / "Cancelled: <reason>", the plain-language line members read. */
    public static function memberLine(string $verbLabel, string $reason): string
    {
        return $verbLabel.': '.$reason;
    }
}
