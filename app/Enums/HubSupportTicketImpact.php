<?php

declare(strict_types=1);

namespace App\Enums;

enum HubSupportTicketImpact: string
{
    case Minor = 'minor';
    case TaskBlocking = 'task_blocking';
    case FeatureUnavailable = 'feature_unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Minor => 'Minor',
            self::TaskBlocking => 'Task blocking',
            self::FeatureUnavailable => 'Feature unavailable',
        };
    }
}
