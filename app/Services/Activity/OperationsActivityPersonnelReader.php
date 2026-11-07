<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Enums\PersonnelRequestStatus;
use App\Filament\Admin\Pages\EmployeeMessages;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource;
use App\Models\Employee;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestUpdate;
use App\Models\User;
use App\Services\Security\EmployeeLogisticsMessagingAccess;
use Carbon\CarbonImmutable;

/**
 * Personnel-lane history for the Latest activity card: uniform and equipment request updates
 * (personnel_request_updates, gate admin.personnel.view like PersonnelRequestResource) and member conversations with
 * Support Services (employee_logistics_messages, gate EmployeeLogisticsMessagingAccess like the Logistics inbox).
 *
 * A conversation needs action while the member wrote last and no person on the team has answered since: the
 * assistant's receipt never counts as a team reply, exactly as in the inbox.
 */
final class OperationsActivityPersonnelReader
{
    private const EVENTS = ['submitted', 'status_changed', 'employee_responded', 'member_amended', 'note_added', 'item_acknowledged', 'item_arrived', 'item_fulfilled', 'items_fulfilled'];

    public static function canReadRequests(User $user): bool
    {
        return $user->can('admin.personnel.view');
    }

    public static function canReadMessages(User $user): bool
    {
        return app(EmployeeLogisticsMessagingAccess::class)->canViewLogisticsInbox($user);
    }

    /** @return list<OperationsActivityEvent> */
    public function events(User $user, CarbonImmutable $since, int $limit): array
    {
        return [
            ...(self::canReadRequests($user) ? $this->requestUpdates($since, $limit) : []),
            ...(self::canReadMessages($user) ? $this->conversations($since, $limit) : []),
        ];
    }

    /** @return list<OperationsActivityEvent> */
    private function requestUpdates(CarbonImmutable $since, int $limit): array
    {
        $rows = PersonnelRequestUpdate::query()
            ->whereIn('event', self::EVENTS)
            ->where('created_at', '>=', $since)
            ->with('request')
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
        $rows = $rows->filter(static fn (PersonnelRequestUpdate $row): bool => $row->request instanceof PersonnelRequest && ! $row->request->isArchived())->values();
        if ($rows->isEmpty()) {
            return [];
        }
        $users = User::query()->whereIn('id', $rows->pluck('changed_by_admin_id')->filter()->unique()->all())->get(['id', 'name', 'display_name'])->keyBy('id');
        $employees = Employee::query()->whereIn('id', $rows->pluck('changed_by_employee_id')->filter()->unique()->all())->pluck('name', 'id');
        $answered = $this->lastStaffUpdateIds($rows->where('event', 'employee_responded')->pluck('personnel_request_id')->unique()->all());

        $out = [];
        foreach ($rows as $row) {
            $admin = $row->changed_by_admin_id !== null ? $users->get($row->changed_by_admin_id) : null;
            $employee = $row->changed_by_employee_id !== null ? (string) ($employees[$row->changed_by_employee_id] ?? '') : '';
            $out[] = $this->fromUpdate($row, $admin, $employee, $answered);
        }

        return $out;
    }

    /** @param array<int, int> $answered request id => newest update id written by staff */
    private function fromUpdate(PersonnelRequestUpdate $update, ?User $admin, string $employee, array $answered): OperationsActivityEvent
    {
        $request = $update->request;
        $event = (string) $update->event;
        $status = $request->status;
        $visible = trim((string) $update->employee_visible_note);
        $internalNote = trim((string) $update->internal_note);
        $terminal = in_array($status, [PersonnelRequestStatus::Completed, PersonnelRequestStatus::Denied, PersonnelRequestStatus::Cancelled], true);

        return new OperationsActivityEvent(
            key: 'personnel_request_updates:'.$update->getKey(),
            domain: OperationsActivityEvent::DOMAIN_PERSONNEL,
            kind: match (true) {
                $event === 'submitted' => 'New request',
                $event === 'employee_responded' => 'Member message',
                $event === 'member_amended' => 'Order edited',
                $event === 'note_added' => 'Note added',
                $event === 'status_changed' => match ($update->status) {
                    PersonnelRequestStatus::Completed => 'Completed',
                    PersonnelRequestStatus::Denied => 'Denied',
                    PersonnelRequestStatus::Cancelled => 'Cancelled',
                    PersonnelRequestStatus::NeedsInformation => 'Information requested',
                    default => 'Status update',
                },
                default => 'Items update',
            },
            subject: trim((string) $request->request_number) ?: null,
            summary: OperationsActivityText::lead($request->type->label(), $visible !== '' ? $visible : ($internalNote !== '' ? $internalNote : (string) $request->beneficiary_name)),
            actorLabel: OperationsActivityText::person($admin) ?? ($employee !== '' ? $employee : ($event === 'submitted' ? (trim((string) $request->requester_name) ?: 'Member') : 'System')),
            at: CarbonImmutable::instance($update->created_at),
            statusLabel: $status->label(),
            actionRequired: ($event === 'submitted' && $status === PersonnelRequestStatus::Pending)
                || ($event === 'employee_responded' && ! $terminal && (int) $update->getKey() > ($answered[$update->personnel_request_id] ?? 0)),
            internal: $visible === '' && $internalNote !== '',
            automated: false,
            message: in_array($event, ['employee_responded', 'note_added'], true) || $visible !== '',
            ownerTag: null,
            // The resource's route key is public_id; the numeric id gives a 404.
            url: PersonnelRequestResource::getUrl('view', ['record' => $request], panel: 'admin'),
        );
    }

    /**
     * @param  list<int>  $requestIds
     * @return array<int, int> request id => newest update written by an administrator
     */
    private function lastStaffUpdateIds(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        return PersonnelRequestUpdate::query()
            ->whereIn('personnel_request_id', $requestIds)
            ->whereNotNull('changed_by_admin_id')
            ->selectRaw('personnel_request_id, MAX(id) as last_id')
            ->groupBy('personnel_request_id')
            ->pluck('last_id', 'personnel_request_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** @return list<OperationsActivityEvent> */
    private function conversations(CarbonImmutable $since, int $limit): array
    {
        $messages = EmployeeLogisticsMessage::query()
            ->where('created_at', '>=', $since)
            ->with('thread')
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        $out = [];
        foreach ($messages as $message) {
            $thread = $message->getRelationValue('thread');
            if (! $thread instanceof EmployeeLogisticsThread) {
                continue;
            }
            $automated = $message->isFromAssistant();
            $fromMember = $message->author_side === EmployeeLogisticsMessage::SIDE_MEMBER;
            $out[] = new OperationsActivityEvent(
                key: 'employee_logistics_messages:'.$message->getKey(),
                domain: OperationsActivityEvent::DOMAIN_PERSONNEL,
                kind: $automated ? 'Support Services reply' : ($fromMember ? 'Member message' : 'Staff reply'),
                subject: trim((string) $thread->member_name) ?: null,
                summary: OperationsActivityText::lead($thread->title(), (string) $message->body),
                actorLabel: $automated ? OperationsActivityText::AUTOMATED_ACTOR : (trim((string) $message->author_name) ?: 'Unknown'),
                at: CarbonImmutable::instance($message->created_at),
                statusLabel: $thread->supportStatus(),
                actionRequired: $fromMember && (int) $message->getKey() > (int) ($thread->last_logistics_message_id ?? 0),
                internal: false,
                automated: $automated,
                message: true,
                ownerTag: null,
                url: EmployeeMessages::getUrl(['thread' => $thread->getKey()], panel: 'admin'),
            );
        }

        return $out;
    }
}
