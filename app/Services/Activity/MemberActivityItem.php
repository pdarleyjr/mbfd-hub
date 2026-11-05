<?php

declare(strict_types=1);

namespace App\Services\Activity;

use Carbon\CarbonImmutable;

/**
 * One row of "My activity": a record the member started or follows, in words a member reads.
 * Built by a source adapter, then given its children, unread state and sort order by MemberActivityFeed.
 */
final readonly class MemberActivityItem
{
    /**
     * @param  string  $key  stable and unique per record, e.g. "inspection:123"; the open link resolves it
     * @param  CarbonImmutable  $at  the latest meaningful activity
     * @param  CarbonImmutable|null  $unreadAt  time of the newest update written by someone else; null if none
     * @param  string|null  $parentKey  the item this one nests under when that item is shown too
     * @param  bool  $routine  a clean record with nothing to follow; it leaves the card sooner
     * @param  list<self>  $children
     */
    public function __construct(
        public string $key,
        public string $category,
        public string $subject,
        public string $description,
        public string $statusLabel,
        public bool $isOpen,
        public bool $needsResponse,
        public ?MemberActivityUpdate $latestUpdate,
        public CarbonImmutable $at,
        public string $url,
        public ?CarbonImmutable $unreadAt = null,
        public bool $unread = false,
        public bool $routine = false,
        public ?string $parentKey = null,
        public ?string $ownerLabel = null,
        public array $children = [],
    ) {}

    /** @param array<string, mixed> $changes constructor argument names and their new values */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** @return list<string> this item's key and every child key */
    public function allKeys(): array
    {
        $keys = [$this->key];
        foreach ($this->children as $child) {
            array_push($keys, ...$child->allKeys());
        }

        return $keys;
    }

    /** Lower-cased words the search box matches against. */
    public function searchText(): string
    {
        return mb_strtolower(trim($this->subject.' '.$this->description.' '.$this->category.' '.$this->statusLabel));
    }
}
