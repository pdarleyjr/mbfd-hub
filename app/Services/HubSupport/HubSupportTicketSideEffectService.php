<?php

declare(strict_types=1);

namespace App\Services\HubSupport;

use App\Models\HubSupportTicket;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use Illuminate\Support\Facades\Notification;

final class HubSupportTicketSideEffectService
{
    public function ticketCreated(HubSupportTicket $ticket): void
    {
        $this->notifySupportRecipients($ticket, 'New Website / App Issue Report', "{$ticket->ticket_number}: {$ticket->generated_title}");
    }

    public function reporterReplied(HubSupportTicket $ticket): void
    {
        $this->notifySupportRecipients($ticket, 'Member replied to Website / App Issue Report', "{$ticket->ticket_number}: {$ticket->generated_title}");
    }

    private function notifySupportRecipients(HubSupportTicket $ticket, string $title, string $body): void
    {
        $recipients = User::query()
            ->whereHas('notificationSubscriptions', fn ($query) => $query
                ->where('event_key', User::NOTIFICATION_PREFERENCE_HUB_SUPPORT_TICKETS)
                ->where(fn ($channels) => $channels
                    ->where('database_enabled', true)
                    ->orWhere('webpush_enabled', true)
                    ->orWhere('email_enabled', true)))
            ->get()
            ->filter(fn (User $user): bool => $user->isAuthenticationAllowed()
                && $user->hasCurrentAdminPanelEntitlement()
                && $user->can('view', $ticket));

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new NewSubmissionNotification(
                submissionType: 'hub_support_ticket',
                title: $title,
                body: $body,
                actionUrl: '/admin/hub-support-tickets/'.$ticket->id,
                icon: 'heroicon-o-lifebuoy',
            ));
        }
    }
}
