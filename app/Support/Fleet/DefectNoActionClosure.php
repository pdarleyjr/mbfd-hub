<?php

declare(strict_types=1);

namespace App\Support\Fleet;

/**
 * The department's wording and reason rules for closing an equipment issue with no
 * corrective action. One place, so the admin dialog, Captain 5, the member pages, the PDF
 * and the service all say (and enforce) the same thing.
 *
 * The stored values never change: action_status 'dismissed', event type 'dismissed',
 * service method dismiss(). Only the words people read are defined here.
 */
final class DefectNoActionClosure
{
    /** The action's name, exactly as the department wrote it. */
    public const ACTION_LABEL = 'Close — No Action Required';

    /** What an issue closed this way is called wherever its status is shown. */
    public const STATUS_LABEL = 'Closed — no action required';

    public const DIALOG_TEXT = 'Use this only when the report was entered incorrectly, is a duplicate, or no corrective action is necessary. Closing it does not mean the equipment was repaired or replaced. The reason and original report remain in the apparatus history.';

    public const REASON_LABEL = 'Why is no corrective action necessary?';

    public const REASON_HELPER = 'Required. The people who reported or confirmed this issue are told this reason.';

    public const SUBMIT_LABEL = 'Close issue';

    public const SUCCESS = 'Issue closed. No action required.';

    /** Trimmed length a reason must reach before it says anything. */
    public const MIN_REASON_LENGTH = 15;

    /** Stand-ins people type to get past a required box (compared lower-case, letters and digits only). */
    private const PLACEHOLDERS = [
        'na', 'none', 'no', 'nothing', 'test', 'testing', 'tbd', 'ok', 'okay', 'x', 'xx', 'xxx', 'asdf', 'abc',
        'noaction', 'noactionneeded', 'noactionrequired', 'nocorrectiveaction', 'nocorrectiveactionnecessary',
        'nothingneeded', 'notneeded', 'unnecessary', 'dismiss', 'dismissed', 'close', 'closed', 'closeissue',
        'noreason', 'reason', 'duplicate', 'mistake', 'error',
    ];

    /** A plain-language problem with the reason, or null when it is acceptable. */
    public static function reasonProblem(?string $reason): ?string
    {
        if (self::isMeaningful($reason, [self::REASON_LABEL, self::ACTION_LABEL, self::STATUS_LABEL, self::SUBMIT_LABEL, 'No corrective action is necessary'])) {
            return null;
        }

        return 'Say why no corrective action is necessary, in at least '.self::MIN_REASON_LENGTH.' characters. For example: "Duplicate of the report from Oct 20" or "Entered on the wrong unit".';
    }

    /**
     * What a reason actually says: markup removed, invisible formatting characters removed, spaces
     * collapsed. Every check below (and the stored length) is made on this, so tags, zero-width
     * characters or padding cannot make a non-reason look like one.
     */
    public static function plainText(?string $reason): string
    {
        $text = strip_tags(html_entity_decode((string) $reason, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = (string) preg_replace('/[\p{Cf}\p{Cc}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * True when the text could be a real reason: long enough, not a stand-in, not the wording of the
     * prompt or button it was typed under, and made of at least three different words of two or more letters.
     *
     * @param  list<string>  $promptLabels  Wording a person may have retyped instead of answering
     */
    public static function isMeaningful(?string $reason, array $promptLabels = []): bool
    {
        $text = self::plainText($reason);
        if ($text === '' || mb_strlen($text) < self::MIN_REASON_LENGTH) {
            return false;
        }

        $letters = self::squash($text);
        $labels = array_map(self::squash(...), $promptLabels);
        if (in_array($letters, self::PLACEHOLDERS, true) || in_array($letters, $labels, true)) {
            return false;
        }

        preg_match_all('/\p{L}{2,}/u', mb_strtolower($text), $matches);
        $distinctWords = count(array_unique($matches[0]));
        $distinctLetters = count(array_unique(preg_split('//u', $letters, -1, PREG_SPLIT_NO_EMPTY) ?: []));

        return $distinctWords >= 3 && $distinctLetters >= 6;
    }

    /** Lower-case letters and digits only, so case, spacing and punctuation never change a comparison. */
    private static function squash(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text));
    }
}
