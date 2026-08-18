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

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Denied, self::Cancelled], true);
    }
}
