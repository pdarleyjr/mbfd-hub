<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApparatusServiceTicket;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\ApparatusServiceTicketEmployeeNotification;
use App\Notifications\NewSubmissionNotification;
use App\Services\Display\DisplaySnapshotService;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Support\Facades\Cache;

class ApparatusServiceTicketSideEffectService
{
    public function ticketCreated(ApparatusServiceTicket $ticket): void
    {
        $this->forgetReadModels($ticket);

        $eventKey = NotificationDispatchService::eventKey(User::NOTIFICATION_PREFERENCE_APPARATUS_SERVICE_TICKETS, 'apparatus_service_ticket', $ticket->id, 'created');

        // Everyone eligible for service tickets right now; saved choices only narrow channels. Queued after the
        // ticket's write commits, and a failure here can never undo that write.
        app(NotificationDispatchService::class)->dispatch(
            User::NOTIFICATION_PREFERENCE_APPARATUS_SERVICE_TICKETS,
            new NewSubmissionNotification(
                submissionType: 'apparatus_service_ticket',
                title: 'New Apparatus Service Ticket',
                body: "{$ticket->ticket_number}: {$ticket->unit_designation_snapshot} — {$ticket->title}",
                actionUrl: '/admin/apparatus-service-tickets/'.$ticket->id,
                icon: 'heroicon-o-wrench-screwdriver',
                eventKey: $eventKey,
            ),
            eventKey: $eventKey,
        );
    }

    public function ticketChanged(ApparatusServiceTicket $ticket, bool $notifyRequester): void
    {
        $this->forgetReadModels($ticket);

        $requester = $ticket->requestedByEmployee;
        if ($notifyRequester && $requester instanceof Employee) {
            $this->notifyRequester($ticket, $requester);
        }
    }

    /**
     * A member's own ticket update is an "update on something I submitted": decided by the evaluator for the
     * member's account. Personnel with no bound account keep the personnel inbox (it is carried over to the
     * account when they first sign in), as before.
     */
    private function notifyRequester(ApparatusServiceTicket $ticket, Employee $requester): void
    {
        try {
            $status = (string) $ticket->status;
            $notification = new ApparatusServiceTicketEmployeeNotification(
                ticketNumber: (string) $ticket->ticket_number,
                unit: (string) $ticket->unit_designation_snapshot,
                status: $status,
                publicNote: $ticket->current_public_response,
                eventKey: NotificationDispatchService::eventKey(User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES, 'apparatus_service_ticket', $ticket->id, 'status:'.$status.':'.\Illuminate\Support\Str::uuid()),
            );
            $account = $requester->user;
            if ($account instanceof User && $account->employee_id === $requester->employee_id) {
                app(NotificationDispatchService::class)->dispatch(User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES, $notification, [$account]);

                return;
            }

            \Filament\Notifications\Notification::make()
                ->title("{$ticket->ticket_number} updated")
                ->body(trim("{$ticket->unit_designation_snapshot}: ".str($status)->replace('_', ' ')->title()->toString().'. '.($ticket->current_public_response ?? '')))
                ->sendToDatabase($requester);
        } catch (\Throwable $exception) {
            report($exception);
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
