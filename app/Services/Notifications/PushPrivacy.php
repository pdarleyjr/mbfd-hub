<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Lock-screen text. A push notification is read by whoever holds the phone, so it never carries a name,
 * a message, a denial reason or an inventory note: only that something needs attention and about which
 * unit or request. The full text stays in the in-app copy (behind sign-in) and the email that category
 * already sent.
 */
final class PushPrivacy
{
    /** The body for any alert that carries a person's words or a reason. */
    public static function messageBody(?string $subject = null): string
    {
        $subject = self::cleanSubject($subject);

        return $subject === null
            ? 'New message - open MBFD Hub to read it.'
            : "New message about {$subject} - open MBFD Hub to read it.";
    }

    /** The body for an alert whose detail (names, equipment, reasons) stays behind sign-in. */
    public static function detailBody(): string
    {
        return 'Open MBFD Hub for details.';
    }

    /** A short unit or request label ("E3", "UNI-2026-014") or null when the text is not one. */
    public static function cleanSubject(?string $subject): ?string
    {
        $subject = trim((string) $subject);
        if ($subject === '' || mb_strlen($subject) > 24 || preg_match('/^[\p{L}\p{N} #\/.\-]+$/u', $subject) !== 1) {
            return null;
        }

        return $subject;
    }

    /** The unit label before the colon of a "E3: message from Captain 5" style title, if there is one. */
    public static function subjectFromTitle(string $title): ?string
    {
        return preg_match('/^([^:]{1,24}):\s/u', $title, $matches) === 1 ? self::cleanSubject($matches[1]) : null;
    }
}
