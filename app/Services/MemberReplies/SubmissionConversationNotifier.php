<?php

declare(strict_types=1);

namespace App\Services\MemberReplies;

use App\Filament\Resources\StationSupplyRequestResource;
use App\Models\ApparatusServiceTicket;
use App\Models\Employee;
use App\Models\StationRequest;
use App\Models\StationSupplyRequest;
use App\Models\SubmissionMessage;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Services\Notifications\NotificationDispatchService;
use App\Support\MemberReplies\ReplySubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Who hears about a reply on a station request, supply request or service ticket (docs/member-replies.md
 * section 5). A member reply goes to the same staff category as the workflow's own new-submission alert; a staff
 * reply goes to the member who submitted it, as an "update on something I submitted". In-app and push only:
 * none of these workflows email, so neither does this. Never throws: the message is already saved.
 */
final class SubmissionConversationNotifier
{
    public function memberReplied(string $type, Model $subject, SubmissionMessage $message, User $member): void
    {
        try {
            [$category, $submissionType, $url] = $this->staffRoute($type, $subject);
            $reference = $this->reference($type, $subject);
            $eventKey = NotificationDispatchService::eventKey($category, $type, (int) $subject->getKey(), 'member_reply:'.$message->getKey());

            app(NotificationDispatchService::class)->dispatch(
                $category,
                new NewSubmissionNotification(
                    submissionType: $submissionType,
                    title: 'Member replied',
                    body: $reference.': '.Str::limit((string) $message->body, 160),
                    actionUrl: $url,
                    icon: 'heroicon-o-chat-bubble-left-right',
                    eventKey: $eventKey,
                    messageBearing: true,
                    pushSubject: $reference,
                ),
                eventKey: $eventKey,
                exceptUserId: (int) $member->getKey(),
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function staffReplied(string $type, Model $subject, SubmissionMessage $message, User $staff): void
    {
        try {
            $recipients = array_values(array_filter(
                $this->participantUsers($type, $subject),
                static fn (User $user): bool => (int) $user->getKey() !== (int) $staff->getKey(),
            ));
            if ($recipients === []) {
                return;
            }
            $reference = $this->reference($type, $subject);
            $eventKey = NotificationDispatchService::eventKey(User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES, $type, (int) $subject->getKey(), 'staff_reply:'.$message->getKey());

            app(NotificationDispatchService::class)->dispatch(
                User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES,
                new NewSubmissionNotification(
                    submissionType: 'member_request_update',
                    title: 'Reply from Support Services',
                    body: $reference.': '.Str::limit((string) $message->body, 160),
                    actionUrl: self::memberUrl($type, (int) $subject->getKey()),
                    icon: 'heroicon-o-chat-bubble-left-right',
                    eventKey: $eventKey,
                    messageBearing: true,
                    pushSubject: $reference,
                ),
                $recipients,
                $eventKey,
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** The member's page for this conversation (employee portal). */
    public static function memberUrl(string $type, int $id): string
    {
        return '/employee/my-conversations/'.$type.'/'.$id;
    }

    /**
     * Accounts of the members on this record.
     *
     * @return list<User>
     */
    public function participantUsers(string $type, Model $subject): array
    {
        $users = [];
        $userId = match (true) {
            $subject instanceof StationRequest, $subject instanceof StationSupplyRequest => $subject->getAttribute('actor_user_id'),
            default => null,
        };
        if ($userId !== null && ($user = User::query()->find($userId)) instanceof User) {
            $users[(int) $user->getKey()] = $user;
        }
        $employeeId = match (true) {
            $subject instanceof StationRequest => $subject->getAttribute('requested_by_employee_id'),
            $subject instanceof StationSupplyRequest => $subject->getAttribute('actor_employee_id'),
            $subject instanceof ApparatusServiceTicket => $subject->getAttribute('requested_by_employee_id'),
            default => null,
        };
        if ($employeeId !== null) {
            $employee = Employee::query()->find($employeeId);
            $account = $employee instanceof Employee ? $employee->user : null;
            if ($account instanceof User && $account->employee_id === $employee->employee_id) {
                $users[(int) $account->getKey()] = $account;
            }
        }

        return array_values(array_filter($users, static fn (User $user): bool => $user->isAuthenticationAllowed()));
    }

    /** @return array{0: string, 1: string, 2: string} category, submission type, admin URL */
    private function staffRoute(string $type, Model $subject): array
    {
        $id = (int) $subject->getKey();

        return match ($type) {
            ReplySubject::STATION_REQUEST => [User::NOTIFICATION_PREFERENCE_STATION_REQUESTS, 'station_request', '/admin/station-requests/'.$id],
            ReplySubject::APPARATUS_SERVICE_TICKET => [User::NOTIFICATION_PREFERENCE_APPARATUS_SERVICE_TICKETS, 'apparatus_service_ticket', '/admin/apparatus-service-tickets/'.$id],
            // Supply requests have no alert of their own; the station inventory staff category is the nearest owner.
            default => [User::NOTIFICATION_PREFERENCE_STATION_INVENTORY_ALERTS, 'station_inventory_submission', $this->supplyListUrl()],
        };
    }

    private function supplyListUrl(): string
    {
        try {
            return StationSupplyRequestResource::getUrl('index', isAbsolute: false, panel: 'admin');
        } catch (\Throwable) {
            return '/admin';
        }
    }

    private function reference(string $type, Model $subject): string
    {
        return match (true) {
            $subject instanceof StationRequest => (string) ($subject->request_number ?: 'Station request #'.$subject->getKey()),
            $subject instanceof ApparatusServiceTicket => (string) ($subject->ticket_number ?: 'Service ticket #'.$subject->getKey()),
            default => 'Supply request #'.$subject->getKey(),
        };
    }
}
