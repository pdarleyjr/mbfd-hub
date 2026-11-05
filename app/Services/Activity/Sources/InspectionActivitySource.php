<?php

declare(strict_types=1);

namespace App\Services\Activity\Sources;

use App\Filament\Employee\Pages\MyVehicleInspectionView;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\Employee;
use App\Models\User;
use App\Services\Activity\ActivitySignal;
use App\Services\Activity\ApparatusLookup;
use App\Services\Activity\MemberActivityItem;
use App\Services\Activity\MemberActivitySource;
use App\Services\Activity\MemberActivityText;
use App\Services\Activity\Readers\IssueMessageSignals;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily Checkouts the member submitted. Ownership is the history service's foreign-key rule (actor user or
 * employee), never the typed operator name. The submitted results are not read: this card needs the status,
 * the reviewers' messages and whether a clarification is waiting on the member.
 */
final class InspectionActivitySource implements MemberActivitySource
{
    public function __construct(
        private readonly EmployeeInspectionHistoryService $history,
        private readonly IssueMessageSignals $messages,
    ) {}

    public function items(User $user, ?Employee $employee, CarbonImmutable $since, int $limit): array
    {
        $inspections = $this->history->ownedQuery($user)
            ->select([
                'apparatus_inspections.id', 'apparatus_inspections.apparatus_id', 'apparatus_inspections.designation_at_time',
                'apparatus_inspections.inspection_reference', 'apparatus_inspections.review_status',
                'apparatus_inspections.completed_at', 'apparatus_inspections.created_at',
            ])
            ->where(fn (Builder $recent) => $recent
                ->where('apparatus_inspections.created_at', '>=', $since)
                ->orWhere('apparatus_inspections.updated_at', '>=', $since)
                ->orWhere('apparatus_inspections.review_status', 'pending_review'))
            ->orderByDesc('apparatus_inspections.id')
            ->limit($limit)
            ->get();
        if ($inspections->isEmpty()) {
            return [];
        }

        $ids = $inspections->modelKeys();
        $signals = $this->messages->forTargets($user, 'apparatus_inspection_id', array_map('intval', $ids));
        // A reviewer asked the member to explain a meter reading and has not heard back yet.
        $waiting = array_flip(ApparatusInspectionException::query()
            ->whereIn('apparatus_inspection_id', $ids)->where('status', 'revision_requested')
            ->distinct()->pluck('apparatus_inspection_id')->map(fn ($id): int => (int) $id)->all());

        $apparatuses = ApparatusLookup::load($inspections->pluck('apparatus_id'));

        return $inspections->map(function (ApparatusInspection $inspection) use ($signals, $waiting, $apparatuses): MemberActivityItem {
            $id = (int) $inspection->getKey();
            $signal = $signals[$id] ?? ActivitySignal::none();
            $pending = $inspection->review_status === 'pending_review';
            $needsResponse = isset($waiting[$id]);
            $at = MemberActivityText::moment($inspection->completed_at ?? $inspection->created_at);
            if ($signal->latest !== null && $signal->latest->at->greaterThan($at)) {
                $at = $signal->latest->at;
            }

            return new MemberActivityItem(
                key: 'inspection:'.$id,
                category: 'Vehicle inspection',
                subject: MemberActivityText::unitLabel($apparatuses->find($inspection->apparatus_id), $inspection->designation_at_time),
                description: 'Daily checkout '.($inspection->inspection_reference ?: '#'.$id),
                statusLabel: $this->history->reviewStatusLabel($inspection->review_status),
                isOpen: $pending || $needsResponse,
                needsResponse: $needsResponse,
                latestUpdate: $signal->latest,
                at: $at,
                url: MyVehicleInspectionView::getUrl(['inspection' => $id], panel: 'employee'),
                unreadAt: $signal->humanAt,
                routine: ! $pending && ! $needsResponse && $inspection->review_status !== 'rejected',
            );
        })->values()->all();
    }
}
