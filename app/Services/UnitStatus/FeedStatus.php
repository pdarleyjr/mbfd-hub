<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use Carbon\CarbonImmutable;

/** How far the page can trust the PulsePoint copy right now (contract section 5). */
final readonly class FeedStatus
{
    public const LIVE = 'live';

    public const DELAYED = 'delayed';

    public const UNAVAILABLE = 'unavailable';

    public const STARTING = 'starting';

    public const UNREACHABLE = 'unreachable';

    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(
        public string $state,
        public bool $fresh,
        public ?CarbonImmutable $lastSyncAt,
        public ?CarbonImmutable $lastSourceReadAt,
        public bool $catchingUp = false,
    ) {}

    public function label(): string
    {
        return match ($this->state) {
            self::LIVE => 'Live',
            self::DELAYED => 'Feed delayed',
            self::UNAVAILABLE => 'Feed unavailable',
            self::STARTING => 'Feed starting',
            self::UNREACHABLE => 'Feed unreachable',
            default => 'Feed not configured',
        };
    }

    /** The short reason shown on a unit whose status cannot be confirmed. */
    public function note(): ?string
    {
        if ($this->catchingUp) {
            return 'Catching up with PulsePoint calls';
        }

        return match ($this->state) {
            self::LIVE => null,
            self::DELAYED => 'PulsePoint feed delayed',
            self::UNAVAILABLE => 'PulsePoint feed unavailable',
            self::STARTING => 'PulsePoint feed starting',
            self::UNREACHABLE => 'Hub cannot reach the feed',
            default => 'Feed not configured',
        };
    }
}
