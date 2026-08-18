<?php

declare(strict_types=1);

namespace App\Enums;

enum ApparatusServiceTicketStatus: string
{
    case Submitted = 'submitted';
    case Acknowledged = 'acknowledged';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case WaitingForParts = 'waiting_for_parts';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [
            self::Submitted->value,
            self::Acknowledged->value,
            self::Scheduled->value,
            self::InProgress->value,
            self::WaitingForParts->value,
        ];
    }

    /** @return list<string> */
    public static function terminalValues(): array
    {
        return [self::Completed->value, self::Cancelled->value];
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::Acknowledged, self::Scheduled, self::Cancelled],
            self::Acknowledged => [self::Scheduled, self::InProgress, self::Cancelled],
            self::Scheduled => [self::InProgress, self::Cancelled],
            self::InProgress => [self::WaitingForParts, self::Completed, self::Cancelled],
            self::WaitingForParts => [self::InProgress, self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted, self::Acknowledged => 'warning',
            self::Scheduled, self::InProgress => 'primary',
            self::WaitingForParts => 'gray',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
