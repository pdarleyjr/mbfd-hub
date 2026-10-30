<?php

declare(strict_types=1);

namespace App\Support\Dashboards;

/**
 * Outcome of IssueSource::perform(). The panel keeps the admin's input on anything but `done`.
 *
 * - done: persisted through the domain's own service; `message` is shown as a success notice.
 * - conflict: the record changed since the panel loaded it (expected version mismatch) — reload, nothing written.
 * - forbidden: the server-side policy refused it (rights changed mid-session) — nothing written.
 * - invalid: input failed validation; `errors` is field => message.
 * - gone: the record no longer exists or left the admin's scope.
 */
final readonly class ActionResult
{
    /** @param  array<string, string>  $errors */
    private function __construct(
        public string $outcome,
        public string $message,
        public array $errors = [],
    ) {}

    public static function done(string $message): self
    {
        return new self('done', $message);
    }

    public static function conflict(string $message = 'Someone else updated this item. The latest version has been loaded; review it and try again.'): self
    {
        return new self('conflict', $message);
    }

    public static function forbidden(string $message = 'You no longer have permission to do that.'): self
    {
        return new self('forbidden', $message);
    }

    /** @param  array<string, string>  $errors */
    public static function invalid(array $errors, string $message = 'Check the highlighted fields.'): self
    {
        return new self('invalid', $message, $errors);
    }

    public static function gone(string $message = 'This item no longer exists.'): self
    {
        return new self('gone', $message);
    }

    public function succeeded(): bool
    {
        return $this->outcome === 'done';
    }
}
