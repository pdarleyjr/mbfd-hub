<?php

declare(strict_types=1);

namespace App\Enums;

enum HubSupportTicketStatus: string
{
    case New = 'new';
    case Acknowledged = 'acknowledged';
    case InProgress = 'in_progress';
    case WaitingForReporter = 'waiting_for_reporter';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function memberLabel(): string
    {
        return match ($this) {
            self::New => 'Received',
            self::Acknowledged => "We're looking at it",
            self::InProgress => "We're working on it",
            self::WaitingForReporter => 'We need something from you',
            self::Resolved => 'Fixed',
            self::Closed => 'Closed',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Acknowledged, self::InProgress],
            self::Acknowledged => [self::InProgress, self::WaitingForReporter, self::Resolved],
            self::InProgress => [self::WaitingForReporter, self::Resolved],
            self::WaitingForReporter => [self::InProgress, self::Resolved],
            self::Resolved => [self::InProgress, self::Closed],
            self::Closed => [self::InProgress],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
