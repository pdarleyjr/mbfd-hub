<?php

declare(strict_types=1);

namespace App\Enums;

enum PersonnelRequestStatus: string
{
    case Pending = 'pending';
    case Acknowledged = 'acknowledged';
    case NeedsInformation = 'needs_information';
    case Ordered = 'ordered';
    case Arrived = 'arrived';
    case ReadyForPickup = 'ready_for_pickup';
    case Completed = 'completed';
    case Denied = 'denied';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    /** One status meaning for member pages and the admin queue: success, danger, warning, info or neutral. */
    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Denied, self::Cancelled => 'danger',
            self::NeedsInformation => 'warning',
            self::ReadyForPickup, self::Arrived => 'info',
            default => 'neutral',
        };
    }

    public function tagClass(): string
    {
        return $this->tone() === 'neutral' ? 'hub-tag' : 'hub-tag hub-tag--'.$this->tone();
    }

    public function filamentColor(): string
    {
        return $this->tone() === 'neutral' ? 'gray' : $this->tone();
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Denied, self::Cancelled], true);
    }
}
