<?php

declare(strict_types=1);

namespace App\Enums;

enum StationRequestStatus: string
{
    case Pending = 'pending';
    case Acknowledged = 'acknowledged';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Ordered = 'ordered';
    case InProgress = 'in_progress';
    case AwaitingParts = 'awaiting_parts';
    case AwaitingVendor = 'awaiting_vendor';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Denied = 'denied';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /** @return list<string> */
    public static function terminalValues(): array
    {
        return [self::Completed->value, self::Denied->value, self::Cancelled->value];
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_values(array_diff(self::values(), self::terminalValues()));
    }

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Acknowledged, self::UnderReview => 'warning',
            self::Approved, self::Scheduled, self::Ordered, self::InProgress => 'primary',
            self::AwaitingParts, self::AwaitingVendor, self::OnHold => 'gray',
            self::Completed => 'success',
            self::Denied, self::Cancelled => 'danger',
        };
    }
}
