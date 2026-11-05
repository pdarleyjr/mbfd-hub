<?php

declare(strict_types=1);

namespace App\Services\Activity\Sources;

use App\Enums\PersonnelRequestStatus;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestItem;
use App\Models\PersonnelRequestUpdate;
use App\Models\User;
use App\Services\Activity\ActivitySignal;
use App\Services\Activity\LatestPerGroup;
use App\Services\Activity\MemberActivityItem;
use App\Services\Activity\MemberActivitySource;
use App\Services\Activity\MemberActivityText;
use App\Services\Activity\MemberActivityUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Uniform and equipment requests the member made or that were made for them. Only `employee_visible_note` is
 * read. "Needs information" is the one state where the request is waiting on the member.
 */
final class PersonnelRequestActivitySource implements MemberActivitySource
{
    private const SHOWN_ITEMS = 3;

    public function items(User $user, ?Employee $employee, CarbonImmutable $since, int $limit): array
    {
        $employeeId = MemberActivityText::employeeId($user, $employee);
        if ($employeeId === null) {
            return [];
        }

        $open = [
            PersonnelRequestStatus::Pending, PersonnelRequestStatus::Acknowledged, PersonnelRequestStatus::NeedsInformation,
            PersonnelRequestStatus::Ordered, PersonnelRequestStatus::Arrived, PersonnelRequestStatus::ReadyForPickup,
        ];
        $requests = PersonnelRequest::query()
            ->active()
            ->select([
                'id', 'public_id', 'request_number', 'type', 'status', 'beneficiary_employee_id',
                'completed_at', 'denied_at', 'cancelled_at', 'created_at', 'updated_at',
            ])
            ->where(fn (Builder $mine) => $mine
                ->where('beneficiary_employee_id', $employeeId)
                ->orWhere('requester_employee_id', $employeeId))
            ->where(fn (Builder $recent) => $recent
                ->whereIn('status', array_map(fn (PersonnelRequestStatus $status): string => $status->value, $open))
                ->orWhere('created_at', '>=', $since)
                ->orWhere('updated_at', '>=', $since))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
        if ($requests->isEmpty()) {
            return [];
        }

        $ids = array_map('intval', $requests->modelKeys());
        $signals = $this->signals($user, $employeeId, $ids);
        $items = PersonnelRequestItem::query()->whereIn('personnel_request_id', $ids)->orderBy('id')
            ->get(['personnel_request_id', 'item_name'])->groupBy('personnel_request_id');

        return $requests->map(function (PersonnelRequest $request) use ($signals, $items): MemberActivityItem {
            $id = (int) $request->getKey();
            $signal = $signals[$id] ?? ActivitySignal::none();
            $status = $request->status;
            $names = $items->get($id)?->pluck('item_name')->unique()->values() ?? collect();
            $more = $names->count() - self::SHOWN_ITEMS;
            $at = MemberActivityText::moment($request->created_at);
            foreach ([$signal->latest?->at, $request->completed_at, $request->denied_at, $request->cancelled_at] as $later) {
                if ($later !== null && MemberActivityText::moment($later)->greaterThan($at)) {
                    $at = MemberActivityText::moment($later);
                }
            }

            return new MemberActivityItem(
                key: 'personnel_request:'.$id,
                category: $request->type->label(),
                subject: (string) $request->request_number,
                description: $names->isEmpty() ? $request->type->label() : $names->take(self::SHOWN_ITEMS)->implode(', ').($more > 0 ? ' and '.$more.' more' : ''),
                statusLabel: $status->label(),
                isOpen: ! $status->isTerminal(),
                needsResponse: $status === PersonnelRequestStatus::NeedsInformation,
                latestUpdate: $signal->latest,
                at: $at,
                // The member it is for and the officer who submitted it both open the detail page (docs/member-replies.md).
                url: route('employee.personnel-requests.show', ['personnelRequest' => $request->public_id]),
                unreadAt: $signal->humanAt,
            );
        })->values()->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, ActivitySignal>
     */
    private function signals(User $user, int $employeeId, array $ids): array
    {
        $shown = PersonnelRequestUpdate::query()
            ->whereIn('personnel_request_id', $ids)
            ->whereNotNull('employee_visible_note')->where('employee_visible_note', '<>', '')
            ->where(fn (Builder $author) => $author->whereNull('changed_by_employee_id')->orWhere('changed_by_employee_id', '<>', $employeeId));
        // Staff acting through the admin console; a row with neither author is the system.
        $people = (clone $shown)->whereNotNull('changed_by_admin_id')->where('changed_by_admin_id', '<>', $user->getKey());

        $signals = [];
        foreach (LatestPerGroup::newest($shown, 'personnel_request_id')->get(['id', 'personnel_request_id', 'employee_visible_note', 'changed_by_admin_id', 'created_at']) as $update) {
            $system = $update->changed_by_admin_id === null;
            $signals[(int) $update->personnel_request_id] = new ActivitySignal(new MemberActivityUpdate(
                MemberActivityText::excerpt((string) $update->employee_visible_note),
                MemberActivityText::DEPARTMENT,
                MemberActivityText::moment($update->created_at),
                $system,
            ));
        }
        foreach (LatestPerGroup::newest($people, 'personnel_request_id')->get(['id', 'personnel_request_id', 'created_at']) as $update) {
            $request = (int) $update->personnel_request_id;
            $signals[$request] = ($signals[$request] ?? ActivitySignal::none())
                ->merge(new ActivitySignal(null, MemberActivityText::moment($update->created_at)));
        }

        return $signals;
    }
}
