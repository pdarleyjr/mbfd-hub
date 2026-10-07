<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Enums\PersonnelRequestType;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestItem;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Notifications\NewSubmissionNotification;
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

    public function statusChanged(PersonnelRequest $request): void
    {
        $this->notifyMember($request, "Request {$request->status->label()}", "{$request->request_number} is now {$request->status->label()}.");
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
        $admins = User::query()->whereHas('roles', fn ($query) => $query->whereIn('name', ['super_admin', 'admin', 'logistics_admin']))->get();
        foreach ($admins as $admin) {
            UserNotificationSubscription::ensurePersonnelRequestsForUser($admin);
            $admin->notify(new NewSubmissionNotification(
                submissionType: $request->type === PersonnelRequestType::Uniform ? 'uniform_request' : 'personnel_equipment_request',
                title: $title,
                body: $body,
                actionUrl: '/admin/personnel-uniforms-equipment/personnel-requests/'.$request->getRouteKey(),
                icon: 'heroicon-o-clipboard-document-check',
            ));
        }
    }

    private function notifyMember(PersonnelRequest $request, string $title, string $body, ?PersonnelRequestItem $item = null): void
    {
        $employee = $request->beneficiary;
        if (! $employee) {
            return;
        }
        $url = '/employee/my-requests/'.$request->getRouteKey().($item ? '#item-'.$item->id : '');
        $user = $employee->user;
        if ($user instanceof User && $user->employee_id === $employee->employee_id) {
            UserNotificationSubscription::ensurePersonnelRequestsForUser($user);
            $user->notify(new NewSubmissionNotification(
                submissionType: 'member_request_update',
                title: $title,
                body: $body,
                actionUrl: $url,
                icon: 'heroicon-o-chat-bubble-left-right',
                essentialInApp: true,
            ));

            return;
        }

        // Preserve alerts for historical personnel without a bound canonical account.
        Notification::make()->title($title)->body($body)
            ->actions([Action::make('view')->url($url)->markAsRead()])
            ->sendToDatabase($employee);
    }
}
