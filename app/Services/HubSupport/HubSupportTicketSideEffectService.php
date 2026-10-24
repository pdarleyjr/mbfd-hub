<?php

declare(strict_types=1);

namespace App\Services\HubSupport;

use App\Jobs\DeliverHubSupportTicketUpdate;
use App\Models\HubSupportNotificationDelivery;
use App\Models\HubSupportTicket;
use App\Models\HubSupportTicketUpdate;
use App\Models\User;
use App\Notifications\HubSupportMemberNotification;
use App\Notifications\NewSubmissionNotification;
use App\Services\Notifications\NotificationRecipientEvaluator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

final class HubSupportTicketSideEffectService
{
    public function ticketCreated(HubSupportTicket $ticket): void
    {
        $this->notifySupportRecipients($ticket, $ticket->updates()->reorder()->latest('id')->firstOrFail(), 'New Website / App Issue Report');
    }

    public function reporterReplied(HubSupportTicket $ticket, ?HubSupportTicketUpdate $update = null): void
    {
        $this->notifySupportRecipients($ticket, $update ?? $ticket->updates()->reorder()->latest('id')->firstOrFail(), 'Member replied to Website / App Issue Report');
    }

    public function memberUpdated(HubSupportTicket $ticket, HubSupportTicketUpdate $update): void
    {
        $message = $update->public_response ?: ($update->metadata['resolution_summary'] ?? 'Your report has an update.');
        $this->record($update, $ticket->reporter, 'member', ['status' => $update->status->memberLabel(), 'message' => $message]);
    }

    private function notifySupportRecipients(HubSupportTicket $ticket, HubSupportTicketUpdate $update, string $title): void
    {
        // Eligibility (admin entitlement + the support policy) is evaluated now; the per-ticket
        // view check and the member's opt-in choices (off by default) narrow it further.
        $recipients = app(NotificationRecipientEvaluator::class)
            ->recipients(User::NOTIFICATION_PREFERENCE_HUB_SUPPORT_TICKETS)
            ->filter(fn (User $user): bool => $user->can('view', $ticket));

        foreach ($recipients as $recipient) {
            $this->record($update, $recipient, 'admin', ['title' => $title, 'message' => "{$ticket->ticket_number}: {$ticket->generated_title}"]);
        }
    }

    private function record(HubSupportTicketUpdate $update, User $recipient, string $audience, array $payload): void
    {
        $notification = $this->message($update->hub_support_ticket_id, $audience, $payload);
        foreach ($notification->via($recipient) as $channel) {
            $delivery = HubSupportNotificationDelivery::query()->firstOrCreate([
                'hub_support_ticket_update_id' => $update->id, 'recipient_user_id' => $recipient->id, 'channel' => $channel,
            ], ['id' => (string) Str::uuid(), 'audience' => $audience, 'payload' => $payload]);
            DB::afterCommit(fn () => $this->dispatch($delivery->id));
        }
    }

    public function dispatch(string $id): void
    {
        try {
            Bus::dispatch(new DeliverHubSupportTicketUpdate($id));
        } catch (Throwable $exception) {
            // The pending row remains available to the bounded retry command.
            report($exception);
        }
    }

    public function retryFailed(HubSupportTicket $ticket, User $actor): int
    {
        abort_unless($actor->can('update', $ticket), 403);

        return DB::transaction(function () use ($ticket, $actor): int {
            $locked = HubSupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            abort_unless($actor->can('update', $locked), 403);
            abort_if($locked->isArchived(), 409, 'Restore this report before retrying its notifications.');
            $failed = HubSupportNotificationDelivery::query()->whereIn('hub_support_ticket_update_id', $locked->updates()->select('id'))
                ->whereNull('delivered_at')->whereNull('cancelled_at')->where('attempts', '>=', 5)
                ->where(fn ($query) => $query->whereNotNull('last_error')->orWhereNull('last_attempted_at')->orWhere('last_attempted_at', '<=', now()->subMinutes(2)))
                ->lockForUpdate()->limit(100)->get();
            if ($failed->isNotEmpty()) {
                $locked->updates()->create([
                    'previous_status' => $locked->status, 'status' => $locked->status, 'changed_by_user_id' => $actor->id,
                    'internal_note' => 'Failed notification deliveries were selected for another attempt.',
                    'metadata' => ['event' => 'notifications_retried', 'deliveries' => $failed->map->only(['id', 'attempts', 'last_error'])->all()],
                ]);
            }
            foreach ($failed as $delivery) {
                $delivery->update(['attempts' => 0, 'last_error' => null, 'last_attempted_at' => null]);
                DB::afterCommit(fn () => $this->dispatch($delivery->id));
            }

            return $failed->count();
        }, 3);
    }

    public function deliver(string $id): void
    {
        $delivery = DB::transaction(function () use ($id): ?HubSupportNotificationDelivery {
            $delivery = HubSupportNotificationDelivery::query()->lockForUpdate()->findOrFail($id);
            if ($delivery->delivered_at !== null || $delivery->cancelled_at !== null || $delivery->attempts >= 5) {
                return null;
            }
            // A committed claim outlives a worker crash; the scheduler can recover it after the lease.
            if ($delivery->last_error === null && $delivery->last_attempted_at?->isAfter(now()->subMinutes(2))) {
                return null;
            }
            $delivery->increment('attempts');
            $delivery->update(['last_attempted_at' => now(), 'last_error' => null]);

            return $delivery;
        }, 3);
        if ($delivery === null) {
            return;
        }
        // Revalidate after committing the claim and immediately before handing off any content.
        $delivery->load(['ticketUpdate.ticket', 'recipient']);
        $ticket = $delivery->ticketUpdate->ticket;
        $recipient = $delivery->recipient;
        $notification = $this->message($ticket->id, $delivery->audience, $delivery->payload);
        if ($ticket->trashed() || $ticket->isArchived() || ! $recipient->isAuthenticationAllowed()
            || ($delivery->audience === 'admin' && (! $recipient->hasCurrentAdminPanelEntitlement() || ! $recipient->can('view', $ticket)))
            || ($delivery->audience === 'member' && $ticket->reported_by_user_id !== $recipient->id)
            || ! in_array($delivery->channel, $notification->via($recipient), true)) {
            $delivery->update(['cancelled_at' => now()]);

            return;
        }
        $notification->id = $delivery->id;
        try {
            if ($delivery->channel === 'database') {
                // Roll back partial channel writes; the committed attempt survives PostgreSQL SQL errors.
                DB::transaction(function () use ($delivery, $recipient, $notification): void {
                    if (! DB::table('notifications')->where('id', $delivery->id)->exists()) {
                        Notification::sendNow($recipient, $notification, [$delivery->channel]);
                    }
                });
            } else {
                // External channels own their budget and uncertain-delivery evidence; never roll that back.
                Notification::sendNow($recipient, $notification, [$delivery->channel]);
            }
            HubSupportNotificationDelivery::query()->whereKey($id)->where('attempts', $delivery->attempts)
                ->whereNull('delivered_at')->whereNull('cancelled_at')->update(['delivered_at' => now(), 'last_error' => null]);
        } catch (Throwable $exception) {
            HubSupportNotificationDelivery::query()->whereKey($id)->where('attempts', $delivery->attempts)
                ->whereNull('delivered_at')->whereNull('cancelled_at')->update(['last_error' => $exception::class]);
            throw $exception;
        }
    }

    private function message(int $ticketId, string $audience, array $payload): HubSupportMemberNotification|NewSubmissionNotification
    {
        return $audience === 'member'
            ? new HubSupportMemberNotification($ticketId, $payload['status'], $payload['message'])
            : new NewSubmissionNotification(
                submissionType: 'hub_support_ticket', title: $payload['title'], body: $payload['message'],
                actionUrl: '/admin/hub-support-tickets/'.$ticketId, icon: 'heroicon-o-lifebuoy',
            );
    }
}
