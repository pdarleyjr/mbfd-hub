<?php

declare(strict_types=1);

namespace App\Enums;

enum HubSupportTicketCategory: string
{
    case PageOrDisplay = 'page_or_display';
    case FormOrWorkflow = 'form_or_workflow';
    case Notification = 'notification';
    case Performance = 'performance';
    case AccountOrAccess = 'account_or_access';
    case MediaControlOrIntegration = 'media_control_or_integration';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PageOrDisplay => 'Page or display',
            self::FormOrWorkflow => 'Form or workflow',
            self::Notification => 'Notification',
            self::Performance => 'Performance',
            self::AccountOrAccess => 'Account or access',
            self::MediaControlOrIntegration => 'Media Control or integration',
            self::Other => 'Other',
        };
    }
}
