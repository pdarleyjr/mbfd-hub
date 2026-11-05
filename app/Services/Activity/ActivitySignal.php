<?php

declare(strict_types=1);

namespace App\Services\Activity;

use Carbon\CarbonImmutable;

/**
 * What one record has said lately: the newest update to show, and the time of the newest update a
 * person other than the member wrote. Only that second time can make an item unread.
 */
final readonly class ActivitySignal
{
    public function __construct(
        public ?MemberActivityUpdate $latest = null,
        public ?CarbonImmutable $humanAt = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /** The newer update to show, and the newer human time, of two signals. */
    public function merge(self $other): self
    {
        $latest = $this->latest;
        if ($other->latest !== null && ($latest === null || $other->latest->at->greaterThan($latest->at))) {
            $latest = $other->latest;
        }
        $human = $this->humanAt;
        if ($other->humanAt !== null && ($human === null || $other->humanAt->greaterThan($human))) {
            $human = $other->humanAt;
        }

        return new self($latest, $human);
    }
}
