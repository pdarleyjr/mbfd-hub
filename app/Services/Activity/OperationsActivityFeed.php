<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Newest meaningful events across the admin submission domains, for the Admin Overview "Latest activity" card and
 * the Activity history page (docs/unified-activity-and-ownership.md section 5).
 *
 * - Chip mapping: apparatus -> equipment issue events and messages, equipment change requests; stations -> station
 *   request updates; personnel -> uniform/equipment request updates and Support Services conversations; all -> everything.
 * - Each reader applies its own domain's visibility rule, so a domain the user may not see contributes nothing.
 * - Every source is read with one bounded, eager-loaded query set (a window of days and a row limit), so cost does
 *   not grow with how many events exist.
 * - The card's list is cached 10 seconds per user + chip, never shared across users. Nothing here writes.
 */
final class OperationsActivityFeed
{
    public const FILTERS = ['all', 'apparatus', 'stations', 'personnel'];

    public const KIND_ALL = 'all';

    public const KIND_NEEDS_ACTION = 'needs_action';

    public const KIND_MESSAGES = 'messages';

    public const KINDS = [self::KIND_ALL, self::KIND_NEEDS_ACTION, self::KIND_MESSAGES];

    public const WINDOW_DAYS = 14;

    public const HISTORY_DAYS = 30;

    public const PER_SOURCE = 30;

    public const HISTORY_PER_SOURCE = 300;

    public const CACHE_SECONDS = 10;

    public function __construct(
        private readonly Cache $cache,
        private readonly OperationsActivityDefectReader $defects,
        private readonly OperationsActivityCorrectionReader $corrections,
        private readonly OperationsActivityStationReader $stations,
        private readonly OperationsActivityPersonnelReader $personnel,
    ) {}

    public static function normalizeFilter(string $filter): string
    {
        return in_array($filter, self::FILTERS, true) ? $filter : 'all';
    }

    public static function normalizeKind(string $kind): string
    {
        return in_array($kind, self::KINDS, true) ? $kind : self::KIND_ALL;
    }

    /** Whether any domain would show this person anything at all. */
    public function canSeeAny(User $user): bool
    {
        return OperationsActivityDefectReader::canRead($user)
            || OperationsActivityCorrectionReader::canRead($user)
            || OperationsActivityStationReader::canRead($user)
            || OperationsActivityPersonnelReader::canReadRequests($user)
            || OperationsActivityPersonnelReader::canReadMessages($user);
    }

    /** @return list<OperationsActivityEvent> newest first */
    public function latest(User $user, string $filter, int $limit = 12, bool $fresh = false): array
    {
        $filter = self::normalizeFilter($filter);
        $key = 'admin-latest-activity:v1:'.$user->getKey().':'.$filter.':'.$limit;
        if (! $fresh) {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $events = array_slice(
            $this->gather($user, $filter, CarbonImmutable::now()->subDays(self::WINDOW_DAYS), self::PER_SOURCE),
            0,
            max(1, $limit),
        );
        $this->cache->put($key, $events, self::CACHE_SECONDS);

        return $events;
    }

    /**
     * The 30-day history behind "View all activity": searchable, newest first, not cached.
     *
     * @return list<OperationsActivityEvent>
     */
    public function history(User $user, string $filter = 'all', string $search = '', string $kind = self::KIND_ALL): array
    {
        $events = self::onlyKind(
            $this->gather($user, self::normalizeFilter($filter), CarbonImmutable::now()->subDays(self::HISTORY_DAYS), self::HISTORY_PER_SOURCE),
            $kind,
        );
        $needle = mb_strtolower(trim($search));
        if ($needle === '') {
            return $events;
        }

        return array_values(array_filter($events, static fn (OperationsActivityEvent $event): bool => str_contains(
            mb_strtolower(implode(' ', [$event->kind, $event->subject, $event->summary, $event->actorLabel, $event->statusLabel, $event->ownerTag])),
            $needle,
        )));
    }

    /**
     * @param  list<OperationsActivityEvent>  $events
     * @return list<OperationsActivityEvent>
     */
    public static function onlyKind(array $events, string $kind): array
    {
        return match (self::normalizeKind($kind)) {
            self::KIND_NEEDS_ACTION => array_values(array_filter($events, static fn (OperationsActivityEvent $event): bool => $event->actionRequired)),
            self::KIND_MESSAGES => array_values(array_filter($events, static fn (OperationsActivityEvent $event): bool => $event->message)),
            default => $events,
        };
    }

    /**
     * Same events, "Needs action" first when asked; otherwise newest first as read.
     *
     * @param  list<OperationsActivityEvent>  $events
     * @return list<OperationsActivityEvent>
     */
    public static function arranged(array $events, string $sort): array
    {
        if ($sort !== OperationsActivityPreferences::ACTIVITY_NEEDS_ACTION_FIRST) {
            return $events;
        }
        usort($events, static fn (OperationsActivityEvent $a, OperationsActivityEvent $b): int => [$b->actionRequired, $b->at->getTimestamp()] <=> [$a->actionRequired, $a->at->getTimestamp()]);

        return $events;
    }

    /** @return list<OperationsActivityEvent> */
    private function gather(User $user, string $filter, CarbonImmutable $since, int $limit): array
    {
        $events = [];
        if (in_array($filter, ['all', 'apparatus'], true)) {
            array_push($events, ...$this->defects->events($user, $since, $limit), ...$this->corrections->events($user, $since, $limit));
        }
        if (in_array($filter, ['all', 'stations'], true)) {
            array_push($events, ...$this->stations->events($user, $since, $limit));
        }
        if (in_array($filter, ['all', 'personnel'], true)) {
            array_push($events, ...$this->personnel->events($user, $since, $limit));
        }

        usort($events, static fn (OperationsActivityEvent $a, OperationsActivityEvent $b): int => [$b->at->getTimestamp(), $b->key] <=> [$a->at->getTimestamp(), $a->key]);

        return $events;
    }
}
