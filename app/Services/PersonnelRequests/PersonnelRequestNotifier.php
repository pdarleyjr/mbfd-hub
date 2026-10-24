<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Enums\PersonnelRequestType;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestItem;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Notifications\NewSubmissionNotification;
use App\Services\Notifications\NotificationDispatchService;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;

final class PersonnelRequestNotifier
{
    public function created(PersonnelRequest $request): void
    {
        $this->notifyAdmins($request, 'New '.$request->type->label(), "{$request->request_number} for {$request->beneficiary_name} is ready for review.");

        if ($request->type === PersonnelRequestType::Equipment) {
            $this->notifyMember($request, 'Personnel equipment request submitted', "{$request->requester_name} submitted {$request->request_number} on your behalf.");
        }
    }

    public function statusChanged(PersonnelRequest $request, ?string $reason = null): void
    {
        $body = "{$request->request_number} is now {$request->status->label()}.";
        if (filled($reason)) {
            // Deny and Cancel carry the admin's reason so the member is not left guessing.
            $body = "{$request->request_number} - {$request->status->label()}: {$reason}";
        }
        $this->notifyMember($request, "Request {$request->status->label()}", $body);
    }

    public function employeeResponded(PersonnelRequest $request, string $event = 'response'): void
    {
        $this->notifyAdmins($request, $event === 'document' ? 'Requested document uploaded' : 'Employee supplied requested information', "{$request->request_number} for {$request->beneficiary_name} needs review.");
    }

    public function memberUpdated(PersonnelRequest $request, string $event = 'message', ?string $message = null, ?PersonnelRequestItem $item = null): void
    {
        $label = match ($event) {
            'acknowledged', 'item_acknowledged' => 'Acknowledged',
            'ordered', 'item_ordered' => 'Ordered',
            'arrived', 'item_arrived' => 'Arrived',
            'ready_for_pickup', 'item_ready_for_pickup' => 'Ready for pickup',
            'fulfilled', 'item_fulfilled' => 'Issued',
            default => 'Message from Support Services',
        };
        $title = $item ? "{$item->item_name}: {$label}" : $label;
        $body = "{$request->request_number}: ".($message ?: ($item ? "{$item->item_name} has an update." : 'Your request has an update.'));
        $this->notifyMember($request, $title, $body, $item);
    }

    public function adminMessageReceived(PersonnelRequest $request, ?string $message = null, ?PersonnelRequestItem $item = null): void
    {
        $title = $item ? "Member reply: {$item->item_name}" : 'Member replied to a personnel request';
        $body = "{$request->request_number} for {$request->beneficiary_name}: ".($message ?: 'A member message needs review.');
        $this->notifyAdmins($request, $title, $body);
    }

    private function notifyAdmins(PersonnelRequest $request, string $title, string $body): void
    {
        $this->guarded(fn () => $this->queueAdminAlert($request, $title, $body));
    }

    /** Telling people about a request never fails or rolls back the request's own write. */
    private function guarded(callable $notify): void
    {
        try {
            $notify();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function queueAdminAlert(PersonnelRequest $request, string $title, string $body): void
    {
        $submissionType = $request->type === PersonnelRequestType::Uniform ? 'uniform_request' : 'personnel_equipment_request';
        $category = (string) User::preferenceKeyForSubmissionType($submissionType);

        // Whoever may review personnel requests right now (the policy, not a role-name list) is worked out by
        // the queued fan-out; a member with no saved choice gets the category defaults, so no row is needed.
        // Queued after the surrounding write commits; the lock screen names the request, never the member or the message.
        app(NotificationDispatchService::class)->dispatch($category, new NewSubmissionNotification(
            submissionType: $submissionType,
            title: $title,
            body: $body,
            actionUrl: '/admin/personnel-uniforms-equipment/personnel-requests/'.$request->getRouteKey(),
            icon: 'heroicon-o-clipboard-document-check',
            messageBearing: true,
            pushSubject: $request->request_number,
        ));
    }

    private function notifyMember(PersonnelRequest $request, string $title, string $body, ?PersonnelRequestItem $item = null): void
    {
        $this->guarded(fn () => $this->queueMemberAlert($request, $title, $body, $item));
    }

    private function queueMemberAlert(PersonnelRequest $request, string $title, string $body, ?PersonnelRequestItem $item = null): void
    {
        $employee = $request->beneficiary;
        if (! $employee) {
            return;
        }
        $url = '/employee/my-requests/'.$request->getRouteKey().($item ? '#item-'.$item->id : '');
        $user = $employee->user;
        if ($user instanceof User && $user->employee_id === $employee->employee_id) {
            UserNotificationSubscription::ensurePersonnelRequestsForUser($user);
            app(NotificationDispatchService::class)->dispatch(User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES, new NewSubmissionNotification(
                submissionType: 'member_request_update',
                title: $title,
                body: $body,
                actionUrl: $url,
                icon: 'heroicon-o-chat-bubble-left-right',
                messageBearing: true,
                pushSubject: $request->request_number,
            ), [$user]);

            return;
        }

        // Preserve alerts for historical personnel without a bound canonical account.
        Notification::make()->title($title)->body($body)
            ->actions([Action::make('view')->url($url)->markAsRead()])
            ->sendToDatabase($employee);
    }
}
