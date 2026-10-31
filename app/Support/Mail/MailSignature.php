<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\User;

/**
 * The signature inserted into a new draft: the member's own name and rank, as editable text. It is built from
 * the signed-in user's profile only. It is escaped here and sanitized again when the draft is saved.
 */
final class MailSignature
{
    /** Room to type above the signature and any quote. The editor drops an empty paragraph but keeps a line break. */
    public const TYPING_SPACE = '<div><br></div><div><br></div>';

    private const DEPARTMENT = 'Miami Beach Fire Department';

    public static function htmlFor(User $user): string
    {
        $name = trim((string) ($user->display_name ?: $user->name));
        $rank = trim((string) $user->rank);
        if ($name === '') {
            return '';
        }
        $lines = array_filter([$name, $rank !== '' ? $rank : null, self::DEPARTMENT]);

        return '<p>--<br>'.implode('<br>', array_map(static fn (string $line): string => e($line), $lines)).'</p>';
    }

    /**
     * Places the signature after the typing space a reply or forward starts with, so the member types above the
     * quoted text and the signature sits between their words and the quote. A new message gets the same typing
     * space above it. Calling this twice never adds a second signature. Only the exact signature counts: a quote
     * that merely mentions the department must not stop the member's own signature being added.
     */
    public static function insertInto(string $bodyHtml, User $user): string
    {
        $signature = self::htmlFor($user);
        if ($signature === '' || str_contains($bodyHtml, $signature)) {
            return $bodyHtml;
        }
        $bodyHtml = trim($bodyHtml);
        if ($bodyHtml === '') {
            return self::TYPING_SPACE.$signature;
        }
        if (str_starts_with($bodyHtml, self::TYPING_SPACE)) {
            return self::TYPING_SPACE.$signature.substr($bodyHtml, strlen(self::TYPING_SPACE));
        }

        return $bodyHtml.$signature;
    }
}
