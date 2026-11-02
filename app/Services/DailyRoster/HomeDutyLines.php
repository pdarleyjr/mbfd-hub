<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

/**
 * What the Home greeting block shows, as plain text, so the page and the refresh endpoint can never
 * disagree. Nothing here is an employee ID. `shift` and `command` are null when the member is not allowed
 * to see duty lines, when the roster cannot be trusted to say, or when reading it failed.
 */
final readonly class HomeDutyLines
{
    public const SHIFT_VERIFIED = 'verified';

    public const SHIFT_SCHEDULED = 'scheduled';

    public const COMMAND_VERIFIED = 'verified';

    public const COMMAND_SCHEDULED = 'scheduled';

    public const COMMAND_AWAITING = 'awaiting';

    public const COMMAND_DELAYED = 'delayed';

    public const COMMAND_HELD = 'held';

    public const COMMAND_REVIEW = 'review';

    /**
     * @param  string  $greeting  "Good afternoon, Peter"
     * @param  string  $dateLabel  The civil date: "Sunday, November 1, 2026"
     * @param  string  $dateIso  The civil date as Y-m-d
     * @param  array{text: string, state: string}|null  $shift  "A Shift"; "A Shift (scheduled)" when only the rotation says so
     * @param  array{text: string, state: string}|null  $command
     * @param  string|null  $asOf  "As of 9:14 AM", with a quiet qualifier when TeleStaff has gone quiet
     * @param  string|null  $rosterUrl  Only when the member may open the Daily Roster
     * @param  string  $nextChangeAt  The next moment the greeting or the duty day changes by the clock (UTC, ISO 8601)
     */
    public function __construct(
        public string $greeting,
        public string $dateLabel,
        public string $dateIso,
        public ?array $shift,
        public ?array $command,
        public ?string $asOf,
        public ?string $rosterUrl,
        public string $nextChangeAt,
    ) {}

    /** Changes only when something a member would read changes, so an open page swaps text only then. */
    public function version(): string
    {
        return substr(hash('sha256', (string) json_encode($this->content(), JSON_THROW_ON_ERROR)), 0, 32);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->content() + ['version' => $this->version()];
    }

    /** @return array<string, mixed> */
    private function content(): array
    {
        return [
            'greeting' => $this->greeting,
            'date' => ['label' => $this->dateLabel, 'iso' => $this->dateIso],
            'shift' => $this->shift,
            'command' => $this->command,
            'as_of' => $this->asOf,
            'roster_url' => $this->rosterUrl,
            'next_change_at' => $this->nextChangeAt,
        ];
    }
}
