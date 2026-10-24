<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserNotificationSubscription;
use Illuminate\Support\Collection;

/**
 * The one answer to "may this member be told about this, and how?".
 *
 * Fixed order, each step able to deny with a machine reason:
 *   1. the account is active
 *   2. the member is eligible for the category NOW (roles/permissions, never stored)
 *   3. the publisher offered the channel (optional mask)
 *   4. the member's saved preference, which can only NARROW what 2 and 3 allow
 *   5. the channel is reachable (a healthy push device, a city email)
 *
 * A saved preference never authorizes anything. Nothing here is cached across
 * calls, so a revoked role is seen the next time a notification is sent or
 * delivered.
 */
final class NotificationRecipientEvaluator
{
    public function __construct(private readonly NotificationCategoryRegistry $registry) {}

    public function registry(): NotificationCategoryRegistry
    {
        return $this->registry;
    }

    /**
     * @param  list<string>|null  $channelMask  Preference channels the publisher offers; null offers all.
     */
    public function decide(User $user, string $categoryKey, ?array $channelMask = null): NotificationDecision
    {
        $category = $this->registry->get($categoryKey);
        if ($category === null) {
            return new NotificationDecision($categoryKey, false, null, NotificationDecision::UNKNOWN_CATEGORY, []);
        }

        $context = new EligibilityContext($user);
        if (! $context->isActive()) {
            return new NotificationDecision($categoryKey, false, null, NotificationDecision::ACCOUNT_INACTIVE, []);
        }

        $rule = $category->matchedRule($context);
        if ($rule === null) {
            return new NotificationDecision($categoryKey, false, null, NotificationDecision::NOT_ELIGIBLE, []);
        }

        $row = $user->notificationSubscriptions()->where('event_key', $categoryKey)->first();
        $preferred = $this->effectivePreference($category, $row);

        $allowed = [];
        $denials = [];
        foreach (NotificationCategory::ALL_CHANNELS as $channel) {
            $reason = $this->channelDenial($user, $category, $channel, $preferred[$channel], $channelMask);
            if ($reason === null) {
                $allowed[] = $channel;
            } else {
                $denials[$channel] = $reason;
            }
        }

        return new NotificationDecision($categoryKey, true, $rule, null, $allowed, $denials);
    }

    /** Steps 1 and 2 for one member: an active account that is eligible for the category right now. */
    public function isEligible(User $user, string $categoryKey): bool
    {
        $category = $this->registry->get($categoryKey);
        if ($category === null) {
            return false;
        }
        $context = new EligibilityContext($user);

        return $context->isActive() && $category->matchedRule($context) !== null;
    }

    /**
     * Eligible recipients (steps 1 and 2) among the candidates, each account once
     * (the database allows one account per personnel record). Without candidates,
     * the category's own prefilter is evaluated.
     *
     * @param  iterable<User>|null  $candidates
     * @return Collection<int, User>
     */
    public function recipients(string $categoryKey, ?iterable $candidates = null): Collection
    {
        $category = $this->registry->get($categoryKey);
        if ($category === null) {
            return collect();
        }

        $pool = $candidates !== null
            ? collect($candidates)
            : (($category->candidates)
                ? ($category->candidates)()->orderBy('id')->get()
                : User::query()->where('account_status', 'active')->orderBy('id')->get());

        return $pool
            ->unique(fn (User $user): int|string => $user->getKey())
            ->filter(fn (User $user): bool => $category->matchedRule(new EligibilityContext($user)) !== null
                && $user->isAuthenticationAllowed())
            ->sortBy(fn (User $user) => $user->getKey())
            ->values();
    }

    /** Preference after applying the saved row (or defaults), supported and locked channels. @return array{database: bool, webpush: bool, email: bool} */
    public function effectivePreference(NotificationCategory $category, ?UserNotificationSubscription $row): array
    {
        $effective = [];
        foreach (NotificationCategory::ALL_CHANNELS as $channel) {
            $wanted = $row === null
                ? $category->defaults[$channel]
                : (bool) $row->getAttribute($channel.'_enabled');
            $effective[$channel] = $category->supports($channel)
                && ($category->isLocked($channel) || $wanted);
        }

        return $effective;
    }

    /** Plain-language explanation of why a channel is not available, for the settings page. */
    public function reachabilityNote(User $user, string $channel): ?string
    {
        return match ($this->reachabilityDenial($user, $channel)) {
            NotificationDecision::NO_DEVICE => 'Turn on alerts for this phone first (see "This device" above).',
            NotificationDecision::NO_CITY_EMAIL => 'Needs a verified city email on your member record.',
            default => null,
        };
    }

    /** @param  list<string>|null  $mask */
    private function channelDenial(User $user, NotificationCategory $category, string $channel, bool $preferred, ?array $mask): ?string
    {
        if (! $category->supports($channel)) {
            return NotificationDecision::NOT_SUPPORTED;
        }
        if ($mask !== null && ! in_array($channel, $mask, true)) {
            return NotificationDecision::NOT_OFFERED;
        }
        if (! $preferred) {
            return NotificationDecision::PREFERENCE_OFF;
        }

        return $this->reachabilityDenial($user, $channel);
    }

    private function reachabilityDenial(User $user, string $channel): ?string
    {
        return match ($channel) {
            NotificationCategory::CHANNEL_WEBPUSH => $this->hasDevice($user) ? null : NotificationDecision::NO_DEVICE,
            NotificationCategory::CHANNEL_EMAIL => filled($user->employeeProfile?->city_email) ? null : NotificationDecision::NO_CITY_EMAIL,
            default => null,
        };
    }

    /**
     * Any enrolled device. A device waiting out a short back-off still counts: the channel stays in the
     * decision and the alert is re-sent to that device when the back-off ends, rather than being dropped.
     */
    public function hasDevice(User $user): bool
    {
        return $user->pushSubscriptions()->exists();
    }
}
