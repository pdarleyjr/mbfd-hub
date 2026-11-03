<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use Carbon\CarbonImmutable;

/** One decoded response from the Command feed (delta or lookup). */
final readonly class SourceFeedPage
{
    /**
     * @param  list<SourceCall>  $calls
     * @param  list<string>  $missing
     * @param  array<string, mixed>  $health  Whitelisted health fields
     */
    public function __construct(
        public array $calls,
        public int $skipped,
        public array $health,
        public ?CarbonImmutable $cursorUpdatedSince,
        public ?string $cursorAfterId,
        public bool $hasMore,
        public array $missing = [],
    ) {}

    public function healthState(): ?string
    {
        $state = $this->health['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    public function lastSourceReadAt(): ?CarbonImmutable
    {
        $value = $this->health['lastSourceReadAt'] ?? null;
        if (! is_string($value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
