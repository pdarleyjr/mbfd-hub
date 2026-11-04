<?php

declare(strict_types=1);

namespace App\Services\Activity\Sources;

use App\Enums\StationRequestStatus;
use App\Models\Employee;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
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
 * Station requests the member submitted (actor user or requesting employee). Only public notes are read;
 * `internal_note` is never selected. An update written by the AI Response assistant is a receipt: it is
 * shown, but it can never make the request unread.
 */
final class StationRequestActivitySource implements MemberActivitySource
{
    public function items(User $user, ?Employee $employee, CarbonImmutable $since, int $limit): array
    {
        $employeeId = MemberActivityText::employeeId($user, $employee);

        $requests = StationRequest::query()
            ->active()
            ->select([
                'id', 'request_number', 'station_id', 'title', 'status', 'current_public_response',
                'completed_at', 'denied_at', 'cancelled_at', 'created_at', 'updated_at',
            ])
            ->where(fn (Builder $mine) => $mine
                ->where('actor_user_id', $user->getKey())
                ->when($employeeId !== null, fn (Builder $q) => $q->orWhere('requested_by_employee_id', $employeeId)))
            ->where(fn (Builder $recent) => $recent
                ->whereIn('status', StationRequestStatus::openValues())
                ->orWhere('created_at', '>=', $since)
                ->orWhere('updated_at', '>=', $since))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
        if ($requests->isEmpty()) {
            return [];
        }

        $signals = $this->signals($user, array_map('intval', $requests->modelKeys()));

        return $requests->map(function (StationRequest $request) use ($signals): MemberActivityItem {
            $id = (int) $request->getKey();
            $signal = $signals[$id] ?? ActivitySignal::none();
            $status = StationRequestStatus::tryFrom((string) $request->status);
            $latest = $signal->latest;
            if ($latest === null && filled($request->current_public_response)) {
                $latest = new MemberActivityUpdate(
                    MemberActivityText::excerpt($request->current_public_response),
                    MemberActivityText::DEPARTMENT,
                    MemberActivityText::moment($request->updated_at ?? $request->created_at),
                );
            }
            $at = MemberActivityText::moment($request->created_at);
            foreach ([$latest?->at, $request->completed_at, $request->denied_at, $request->cancelled_at] as $later) {
                if ($later !== null && MemberActivityText::moment($later)->greaterThan($at)) {
                    $at = MemberActivityText::moment($later);
                }
            }

            return new MemberActivityItem(
                key: 'station_request:'.$id,
                category: 'Station request',
                subject: (string) ($request->request_number ?: 'Request #'.$id),
                description: (string) $request->title,
                statusLabel: $status?->label() ?? str((string) $request->status)->replace('_', ' ')->title()->toString(),
                isOpen: in_array((string) $request->status, StationRequestStatus::openValues(), true),
                needsResponse: false,
                latestUpdate: $latest,
                at: $at,
                // The station page has no tab address yet; the request is under its Requests tab.
                url: '/daily/stations/'.(int) $request->station_id,
                unreadAt: $signal->humanAt,
            );
        })->values()->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, ActivitySignal>
     */
    private function signals(User $user, array $ids): array
    {
        $me = $user->getKey();
        // The member's own submission note is not news to them.
        $shown = StationRequestUpdate::query()
            ->whereIn('station_request_id', $ids)
            ->whereNotNull('public_note')->where('public_note', '<>', '')
            ->where(fn (Builder $author) => $author->whereNull('changed_by_user_id')->orWhere('changed_by_user_id', '<>', $me));
        $people = (clone $shown)->whereNull('automation_intake_id')->whereNotNull('changed_by_user_id');

        $signals = [];
        foreach (LatestPerGroup::newest($shown, 'station_request_id')->get(['id', 'station_request_id', 'public_note', 'automation_intake_id', 'created_at']) as $update) {
            $automated = $update->isAutoReceipt();
            $signals[(int) $update->station_request_id] = new ActivitySignal(new MemberActivityUpdate(
                MemberActivityText::excerpt((string) $update->public_note),
                $automated ? StationRequestUpdate::autoReceiptSender() : MemberActivityText::DEPARTMENT,
                MemberActivityText::moment($update->created_at),
                $automated,
            ));
        }
        foreach (LatestPerGroup::newest($people, 'station_request_id')->get(['id', 'station_request_id', 'created_at']) as $update) {
            $request = (int) $update->station_request_id;
            $signals[$request] = ($signals[$request] ?? ActivitySignal::none())
                ->merge(new ActivitySignal(null, MemberActivityText::moment($update->created_at)));
        }

        return $signals;
    }
}
