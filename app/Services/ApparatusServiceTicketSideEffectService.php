<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApparatusServiceTicket;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\ApparatusServiceTicketEmployeeNotification;
use App\Notifications\NewSubmissionNotification;
use App\Services\Display\DisplaySnapshotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class ApparatusServiceTicketSideEffectService
{
    public function ticketCreated(ApparatusServiceTicket $ticket): void
    {
        $this->forgetReadModels($ticket);

        $recipients = User::query()
            ->whereHas('notificationSubscriptions', fn ($query) => $query
                ->where('event_key', User::NOTIFICATION_PREFERENCE_APPARATUS_SERVICE_TICKETS)
                ->where(fn ($channels) => $channels
                    ->where('database_enabled', true)
                    ->orWhere('webpush_enabled', true)
                    ->orWhere('email_enabled', true)))
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new NewSubmissionNotification(
                submissionType: 'apparatus_service_ticket',
                title: 'New Apparatus Service Ticket',
                body: "{$ticket->ticket_number}: {$ticket->unit_designation_snapshot} — {$ticket->title}",
                actionUrl: '/admin/apparatus-service-tickets/'.$ticket->id,
                icon: 'heroicon-o-wrench-screwdriver',
            ));
        }
    }

    public function ticketChanged(ApparatusServiceTicket $ticket, bool $notifyRequester): void
    {
        $this->forgetReadModels($ticket);

        $requester = $ticket->requestedByEmployee;
        if ($notifyRequester && $requester instanceof Employee) {
            $requester->notify(new ApparatusServiceTicketEmployeeNotification(
                ticketNumber: (string) $ticket->ticket_number,
                unit: $ticket->unit_designation_snapshot,
                status: $ticket->status,
                publicNote: $ticket->current_public_response,
            ));
        }
    }

    private function forgetReadModels(ApparatusServiceTicket $ticket): void
    {
        Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
        Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
        Cache::forget("station.{$ticket->station_id}.detail");
        Cache::forget("station.{$ticket->station_id}.activity");
        Cache::forget("station.{$ticket->station_id}.apparatus-service-tickets");
        Cache::forget("apparatus.{$ticket->apparatus_id}.service-notices");
    }
}
