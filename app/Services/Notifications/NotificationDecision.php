<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Notifications\Channels\BudgetedMailChannel;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * The outcome of evaluating one member for one category: who matched which
 * rule, which channels are allowed, and a machine reason for every channel
 * that is not.
 */
final class NotificationDecision
{
    public const ACCOUNT_INACTIVE = 'account_inactive';

    public const UNKNOWN_CATEGORY = 'unknown_category';

    public const NOT_ELIGIBLE = 'not_eligible_now';

    public const NOT_SUPPORTED = 'channel_not_supported';

    public const NOT_OFFERED = 'channel_not_offered';

    public const PREFERENCE_OFF = 'preference_off';

    public const NO_DEVICE = 'no_device';

    public const NO_CITY_EMAIL = 'no_city_email';

    /**
     * @param  list<string>  $channels  Allowed preference channels (database, webpush, email).
     * @param  array<string, string>  $denials  Preference channel => reason it is not allowed.
     */
    public function __construct(
        public readonly string $category,
        public readonly bool $eligible,
        public readonly ?string $rule,
        public readonly ?string $denial,
        public readonly array $channels,
        public readonly array $denials = [],
    ) {}

    /** Laravel channel identifiers for the allowed channels. @return list<string> */
    public function laravelChannels(): array
    {
        return array_map(self::toLaravelChannel(...), $this->channels);
    }

    /** Accepts a preference channel name or a Laravel channel identifier. */
    public function allows(string $channel): bool
    {
        return in_array(self::toPreferenceChannel($channel), $this->channels, true);
    }

    public static function toLaravelChannel(string $channel): string
    {
        return match ($channel) {
            NotificationCategory::CHANNEL_WEBPUSH => WebPushChannel::class,
            NotificationCategory::CHANNEL_EMAIL => BudgetedMailChannel::class,
            default => $channel,
        };
    }

    public static function toPreferenceChannel(string $channel): string
    {
        return match ($channel) {
            WebPushChannel::class => NotificationCategory::CHANNEL_WEBPUSH,
            BudgetedMailChannel::class => NotificationCategory::CHANNEL_EMAIL,
            default => $channel,
        };
    }

    /** @return array{category: string, rule: ?string} */
    public function provenance(): array
    {
        return ['category' => $this->category, 'rule' => $this->rule];
    }
}
