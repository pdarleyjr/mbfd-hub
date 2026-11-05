<?php

declare(strict_types=1);

namespace App\Services\Activity;

use Carbon\CarbonImmutable;

/**
 * One line of the admin "Latest activity" card: something a person or the assistant did to a record,
 * read from the record's own history table. Plain text only (Blade escapes it); nothing here can act.
 */
final readonly class OperationsActivityEvent
{
    public const DOMAIN_APPARATUS = 'apparatus';

    public const DOMAIN_STATIONS = 'stations';

    public const DOMAIN_PERSONNEL = 'personnel';

    /**
     * @param  string  $key  stable identity, "<table>:<id>", used for wire:key and de-duplication.
     * @param  string  $domain  one of the DOMAIN_ constants; decides which chip shows it.
     * @param  string  $kind  plain label such as "New issue" or "Member message".
     * @param  string|null  $subject  unit designation, station name or request number.
     * @param  string  $summary  at most 140 characters of what was said or done.
     * @param  string  $actorLabel  person, "Captain 5 · name", or "Support Services (automated)".
     * @param  string  $statusLabel  where the parent record stands now, not when the event happened.
     * @param  bool  $actionRequired  a person still has to answer or decide (never true for an automated receipt).
     * @param  bool  $internal  the summary is a staff-only note.
     * @param  bool  $automated  written by the assistant, not a person.
     * @param  bool  $message  a conversation entry, so the "Messages" mini filter shows it.
     * @param  string|null  $ownerTag  "Captain 5" when Captain 5 works the item; null otherwise.
     */
    public function __construct(
        public string $key,
        public string $domain,
        public string $kind,
        public ?string $subject,
        public string $summary,
        public string $actorLabel,
        public CarbonImmutable $at,
        public string $statusLabel,
        public bool $actionRequired,
        public bool $internal,
        public bool $automated,
        public bool $message,
        public ?string $ownerTag,
        public ?string $url,
    ) {}
}
