<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Closure;

/**
 * One kind of alert a member can receive, described in plain language.
 *
 * The `key` is the stored `user_notification_subscriptions.event_key`, so every
 * existing preference row keeps its meaning. Eligibility is a closure over the
 * member's CURRENT state and returns the name of the rule that matched (kept as
 * provenance) or null. A stored preference can only narrow what eligibility
 * allows; it never grants anything.
 */
final class NotificationCategory
{
    public const CHANNEL_DATABASE = 'database';

    public const CHANNEL_WEBPUSH = 'webpush';

    public const CHANNEL_EMAIL = 'email';

    /** @var list<string> */
    public const ALL_CHANNELS = [self::CHANNEL_DATABASE, self::CHANNEL_WEBPUSH, self::CHANNEL_EMAIL];

    /**
     * @param  list<string>  $channels  Channels this category can use at all.
     * @param  list<string>  $locked  Supported channels that cannot be turned off.
     * @param  array{database: bool, webpush: bool, email: bool}  $defaults  Used when the member has no saved row.
     * @param  Closure(EligibilityContext): ?string  $eligibility
     * @param  Closure(): \Illuminate\Database\Eloquent\Builder<\App\Models\User>|null  $candidates  Cheap prefilter used to pick who to evaluate.
     * @param  bool  $senderWired  False while a category only reserves its rule (hidden from the settings page).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly string $group,
        public readonly array $channels,
        public readonly array $locked,
        public readonly array $defaults,
        public readonly string $significance,
        public readonly Closure $eligibility,
        public readonly ?Closure $candidates = null,
        public readonly bool $senderWired = true,
    ) {}

    public function supports(string $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    public function isLocked(string $channel): bool
    {
        return in_array($channel, $this->locked, true);
    }

    /** Evaluated against the member's current roles and permissions. */
    public function matchedRule(EligibilityContext $context): ?string
    {
        return ($this->eligibility)($context);
    }
}
