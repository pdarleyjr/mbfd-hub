<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\Employee;
use App\Models\MemberActivityRead;
use App\Models\User;
use App\Services\Activity\Sources\AccidentReportActivitySource;
use App\Services\Activity\Sources\DefectActivitySource;
use App\Services\Activity\Sources\InspectionActivitySource;
use App\Services\Activity\Sources\InventoryCorrectionActivitySource;
use App\Services\Activity\Sources\LogisticsMessageActivitySource;
use App\Services\Activity\Sources\PersonnelRequestActivitySource;
use App\Services\Activity\Sources\StationRequestActivitySource;
use App\Services\Activity\Sources\StretcherReportActivitySource;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * "My activity": the member's own records from every domain, composed into one list.
 *
 * Each domain adapter reads its own tables and returns member-safe items. This class nests child items under
 * their parent (an inspection's issues), works out unread from `member_activity_reads`, applies the card's
 * retention rules and orders the result. Nothing here writes to a source record; the only write is the
 * member's own "seen" marker.
 *
 * Unread means: someone other than the member, and not an automated receipt, wrote an update newer than the
 * time the member last opened the item from this list. The member's own actions never make anything unread.
 */
final class MemberActivityFeed
{
    /** @var list<class-string<MemberActivitySource>> */
    private const SOURCES = [
        InspectionActivitySource::class,
        DefectActivitySource::class,
        StretcherReportActivitySource::class,
        AccidentReportActivitySource::class,
        StationRequestActivitySource::class,
        PersonnelRequestActivitySource::class,
        InventoryCorrectionActivitySource::class,
        LogisticsMessageActivitySource::class,
    ];

    /**
     * The compact dashboard card: priority order, retention applied, at most the configured number of parents.
     *
     * @return list<MemberActivityItem>
     */
    public function forCard(User $user, ?Employee $employee = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $items = $this->build(
            $user,
            $employee,
            $now->subDays((int) config('activity.member.card_window_days', 90)),
            (int) config('activity.member.card_source_limit', 40),
        );

        $visible = array_values(array_filter($items, fn (MemberActivityItem $item): bool => $this->staysOnCard($item, $now)));

        return array_slice($this->prioritised($visible), 0, max(1, (int) config('activity.member.card_limit', 10)));
    }

    /**
     * Everything in the page window, in priority order. Narrow it with filter(): the page loads this once and
     * derives both the category list and the visible rows from it.
     *
     * @return list<MemberActivityItem>
     */
    public function forPage(User $user, ?Employee $employee = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return $this->prioritised($this->build(
            $user,
            $employee,
            $now->subDays((int) config('activity.member.page_window_days', 180)),
            (int) config('activity.member.page_source_limit', 150),
        ));
    }

    /**
     * Server-side narrowing for the page: one category, and every typed word must appear in the item or one of
     * its children (subject, description, category, status). Order is kept.
     *
     * @param  list<MemberActivityItem>  $items
     * @return list<MemberActivityItem>
     */
    public function filter(array $items, string $search = '', string $category = ''): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($search))) ?: []));

        return array_values(array_filter($items, function (MemberActivityItem $item) use ($category, $words): bool {
            if ($category !== '' && $item->category !== $category) {
                return false;
            }
            $haystack = $item->searchText();
            foreach ($item->children as $child) {
                $haystack .= ' '.$child->searchText();
            }
            foreach ($words as $word) {
                if (! str_contains($haystack, $word)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param  list<MemberActivityItem>  $items
     * @return list<string> the categories present, for the filter list
     */
    public function categories(array $items): array
    {
        $categories = array_values(array_unique(array_map(fn (MemberActivityItem $item): string => $item->category, $items)));
        sort($categories);

        return $categories;
    }

    /**
     * One of THIS member's items by key, parent or child; null for anything else. The key alone proves nothing:
     * the item has to come out of the member's own sources.
     */
    public function find(User $user, ?Employee $employee, string $key, ?CarbonImmutable $now = null): ?MemberActivityItem
    {
        $now ??= CarbonImmutable::now();
        $items = $this->build(
            $user,
            $employee,
            $now->subDays((int) config('activity.member.lookup_window_days', 365)),
            (int) config('activity.member.lookup_source_limit', 250),
        );

        return $this->locate($items, $key);
    }

    /** Record that the member opened this item (and the items nested under it) now. */
    public function markSeen(User $user, MemberActivityItem $item, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $rows = array_map(fn (string $key): array => [
            'user_id' => $user->getKey(),
            'item_key' => $key,
            'seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $item->allKeys());

        MemberActivityRead::query()->upsert($rows, ['user_id', 'item_key'], ['seen_at', 'updated_at']);
    }

    /**
     * @return list<MemberActivityItem> parents, with children nested and unread worked out
     */
    private function build(User $user, ?Employee $employee, CarbonImmutable $since, int $limit): array
    {
        $all = [];
        foreach (self::SOURCES as $source) {
            try {
                array_push($all, ...app($source)->items($user, $employee, $since, $limit));
            } catch (Throwable $exception) {
                // One domain failing must not blank the member's whole activity list.
                report($exception);
            }
        }
        if ($all === []) {
            return [];
        }

        $seen = $this->seen($user, array_map(fn (MemberActivityItem $item): string => $item->key, $all));
        $byKey = [];
        foreach ($all as $item) {
            $byKey[$item->key] = $item;
        }

        $children = [];
        foreach ($byKey as $key => $item) {
            if ($item->parentKey !== null && $item->parentKey !== $key && isset($byKey[$item->parentKey])) {
                $children[$item->parentKey][] = $item->with(['unread' => $this->isUnread($item, $seen)]);
                unset($byKey[$key]);
            }
        }

        $parents = [];
        foreach ($byKey as $key => $parent) {
            $parents[] = $this->withChildren($parent, $children[$key] ?? [], $seen);
        }

        return $parents;
    }

    /**
     * @param  list<MemberActivityItem>  $children
     * @param  array<string, CarbonImmutable>  $seen
     */
    private function withChildren(MemberActivityItem $parent, array $children, array $seen): MemberActivityItem
    {
        $unread = $this->isUnread($parent, $seen);
        if ($children === []) {
            return $parent->with(['unread' => $unread]);
        }
        usort($children, fn (MemberActivityItem $a, MemberActivityItem $b): int => $b->at <=> $a->at);

        $latest = $parent->latestUpdate;
        $at = $parent->at;
        foreach ($children as $child) {
            $unread = $unread || $child->unread;
            if ($child->at->greaterThan($at)) {
                $at = $child->at;
            }
            if ($child->latestUpdate !== null && ($latest === null || $child->latestUpdate->at->greaterThan($latest->at))) {
                // The parent row shows the newest word on any of its issues, with the issue named.
                $latest = new MemberActivityUpdate(
                    MemberActivityText::excerpt($child->description.': '.$child->latestUpdate->excerpt),
                    $child->latestUpdate->authorLabel,
                    $child->latestUpdate->at,
                    $child->latestUpdate->automated,
                );
            }
        }

        return $parent->with([
            'children' => $children,
            'unread' => $unread,
            'isOpen' => $parent->isOpen || $this->any($children, fn (MemberActivityItem $c): bool => $c->isOpen),
            'needsResponse' => $parent->needsResponse || $this->any($children, fn (MemberActivityItem $c): bool => $c->needsResponse),
            'routine' => false,
            'latestUpdate' => $latest,
            'at' => $at,
        ]);
    }

    /** @param list<MemberActivityItem> $items */
    private function any(array $items, callable $test): bool
    {
        foreach ($items as $item) {
            if ($test($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, CarbonImmutable>  $seen
     */
    private function isUnread(MemberActivityItem $item, array $seen): bool
    {
        if ($item->unreadAt === null) {
            return false;
        }
        // Updates written before My activity existed were already read elsewhere; they never light the card up.
        $since = config('activity.member.unread_since');
        if (is_string($since) && $since !== '' && $item->unreadAt->lessThan(CarbonImmutable::parse($since))) {
            return false;
        }

        return ! isset($seen[$item->key]) || $item->unreadAt->greaterThan($seen[$item->key]);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, CarbonImmutable> item key => when the member last opened it
     */
    private function seen(User $user, array $keys): array
    {
        $seen = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $rows = MemberActivityRead::query()->where('user_id', $user->getKey())->whereIn('item_key', $chunk)->get(['item_key', 'seen_at']);
            foreach ($rows as $row) {
                $seen[(string) $row->item_key] = MemberActivityText::moment($row->seen_at);
            }
        }

        return $seen;
    }

    /** Needs-a-reply and open items stay; the rest age out by their kind. */
    private function staysOnCard(MemberActivityItem $item, CarbonImmutable $now): bool
    {
        if ($item->needsResponse || $item->isOpen) {
            return true;
        }
        $hours = $item->routine
            ? (int) config('activity.member.routine_hours', 48)
            : (int) config('activity.member.closed_hours', 72);

        return $item->at->greaterThanOrEqualTo($now->subHours($hours));
    }

    /**
     * Needs your reply, then unread updates, then open items, then recent routine ones; newest first within each.
     *
     * @param  list<MemberActivityItem>  $items
     * @return list<MemberActivityItem>
     */
    private function prioritised(array $items): array
    {
        $tier = static fn (MemberActivityItem $item): int => match (true) {
            $item->needsResponse => 0,
            $item->unread => 1,
            $item->isOpen => 2,
            default => 3,
        };
        usort($items, static fn (MemberActivityItem $a, MemberActivityItem $b): int => [$tier($a), $b->at->getTimestamp(), $a->key]
            <=> [$tier($b), $a->at->getTimestamp(), $b->key]);

        return $items;
    }

    /** @param list<MemberActivityItem> $items */
    private function locate(array $items, string $key): ?MemberActivityItem
    {
        foreach ($items as $item) {
            if ($item->key === $key) {
                return $item;
            }
            $child = $this->locate($item->children, $key);
            if ($child !== null) {
                return $child;
            }
        }

        return null;
    }
}
