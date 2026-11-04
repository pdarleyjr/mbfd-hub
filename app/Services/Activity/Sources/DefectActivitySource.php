<?php

declare(strict_types=1);

namespace App\Services\Activity\Sources;

use App\Filament\Employee\Pages\MyVehicleInspections;
use App\Filament\Employee\Pages\MyVehicleInspectionView;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectParticipant;
use App\Models\Employee;
use App\Models\User;
use App\Services\Activity\ActivitySignal;
use App\Services\Activity\MemberActivityItem;
use App\Services\Activity\MemberActivitySource;
use App\Services\Activity\MemberActivityText;
use App\Services\Activity\Readers\DefectEventSignals;
use App\Services\Activity\Readers\IssueMessageSignals;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use App\Support\Fleet\DefectActionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Equipment issues the member reported or joined. Standalone stretcher reports are left to
 * StretcherReportActivitySource so one report is never listed twice.
 *
 * Never selected: `photo` (a base64 image), `notes`, `resolution_notes`, `action_details`, `defect_history`.
 */
final class DefectActivitySource implements MemberActivitySource
{
    private const COLUMNS = [
        'id', 'apparatus_id', 'apparatus_inspection_id', 'compartment', 'item', 'issue_type', 'resolved', 'resolved_at',
        'action_status', 'compartment_key', 'first_reported_inspection_id', 'first_reported_at', 'last_observed_inspection_id',
        'created_at', 'updated_at',
    ];

    /** Columns added by the ownership migration (2026_11_04_150000). */
    private const OWNERSHIP_COLUMNS = ['responsible_owner', 'correction_reported_at'];

    public function __construct(
        private readonly EmployeeInspectionHistoryService $history,
        private readonly IssueMessageSignals $messages,
        private readonly DefectEventSignals $events,
    ) {}

    public function items(User $user, ?Employee $employee, CarbonImmutable $since, int $limit): array
    {
        $userId = $user->getKey();
        $employeeId = MemberActivityText::employeeId($user, $employee);

        $defects = ApparatusDefect::query()
            ->select($this->columns())
            ->where(fn (Builder $mine) => $mine
                ->where('first_reported_by_user_id', $userId)
                ->orWhereIn('id', $this->participation($userId, $employeeId)->select('apparatus_defect_id')))
            ->where(fn (Builder $kind) => $kind->whereNull('compartment_key')->orWhere('compartment_key', '<>', 'stretcher_report'))
            ->where(fn (Builder $recent) => $recent
                ->where('resolved', false)
                ->orWhere('created_at', '>=', $since)
                ->orWhere('updated_at', '>=', $since))
            ->with('apparatus:id,designation,name,unit_id,slug')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
        if ($defects->isEmpty()) {
            return [];
        }

        $ids = array_map('intval', $defects->modelKeys());
        $signals = $this->signals($user, $ids);
        $viewOn = $this->inspectionsToOpen($user, $userId, $employeeId, $defects, $ids);

        return $defects->map(function (ApparatusDefect $defect) use ($signals, $viewOn): MemberActivityItem {
            $id = (int) $defect->getKey();
            $signal = $signals[$id] ?? ActivitySignal::none();
            $closed = (bool) $defect->resolved || DefectActionStatus::isClosed($defect->action_status);
            $at = MemberActivityText::moment($defect->first_reported_at ?? $defect->created_at);
            foreach ([$signal->latest?->at, $defect->resolved_at] as $later) {
                if ($later !== null && MemberActivityText::moment($later)->greaterThan($at)) {
                    $at = MemberActivityText::moment($later);
                }
            }
            $viewing = $viewOn[$id] ?? null;
            $first = $defect->first_reported_inspection_id;

            return new MemberActivityItem(
                key: 'defect:'.$id,
                category: 'Equipment issue',
                subject: MemberActivityText::unitLabel($defect->apparatus),
                description: trim(($defect->issue_type ? str((string) $defect->issue_type)->headline().': ' : '').$defect->item),
                statusLabel: MemberActivityText::defectStatus($closed, $defect->action_status, $defect->getAttribute('correction_reported_at') !== null),
                isOpen: ! $closed,
                needsResponse: false,
                latestUpdate: $signal->latest,
                at: $at,
                url: $viewing !== null
                    ? MyVehicleInspectionView::getUrl(['inspection' => $viewing], panel: 'employee').'#issue-'.$id
                    : $this->issuePageUrl($defect),
                unreadAt: $signal->humanAt,
                // Nests under the checkout that first reported it, when that checkout is the member's own.
                parentKey: $first !== null && $viewing === (int) $first ? 'inspection:'.(int) $first : null,
                ownerLabel: $closed ? null : MemberActivityText::ownerLabel($defect->getAttribute('responsible_owner')),
            );
        })->values()->all();
    }

    /** @return Builder<ApparatusDefectParticipant> */
    private function participation(int|string $userId, ?int $employeeId): Builder
    {
        return ApparatusDefectParticipant::query()->where(fn (Builder $who) => $who
            ->where('user_id', $userId)
            ->when($employeeId !== null, fn (Builder $q) => $q->orWhere('employee_id', $employeeId)));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, ActivitySignal>
     */
    private function signals(User $user, array $ids): array
    {
        $signals = $this->messages->forTargets($user, 'apparatus_defect_id', $ids);
        foreach ($this->events->forDefects($user, $ids) as $id => $signal) {
            $signals[$id] = ($signals[$id] ?? ActivitySignal::none())->merge($signal);
        }

        return $signals;
    }

    /**
     * For each issue, the member's own inspection to open it from: only an inspection they own is linked,
     * because that page refuses anyone else. Two queries, however many issues.
     *
     * @param  Collection<int, ApparatusDefect>  $defects
     * @param  list<int>  $ids
     * @return array<int, int> defect id => inspection id
     */
    private function inspectionsToOpen(User $user, int|string $userId, ?int $employeeId, Collection $defects, array $ids): array
    {
        $mine = $this->participation($userId, $employeeId)->whereIn('apparatus_defect_id', $ids)
            ->get(['apparatus_defect_id', 'first_inspection_id', 'last_inspection_id'])->groupBy('apparatus_defect_id');

        $candidates = [];
        foreach ($defects as $defect) {
            $list = [$defect->first_reported_inspection_id, $defect->apparatus_inspection_id];
            foreach ($mine->get($defect->getKey(), []) as $participation) {
                array_push($list, $participation->first_inspection_id, $participation->last_inspection_id);
            }
            $list[] = $defect->last_observed_inspection_id;
            $candidates[(int) $defect->getKey()] = array_values(array_unique(array_map('intval', array_filter($list))));
        }
        $all = array_values(array_unique(array_merge(...array_values($candidates))));
        if ($all === []) {
            return [];
        }
        $owned = array_flip($this->history->ownedQuery($user)->whereIn('apparatus_inspections.id', $all)
            ->pluck('apparatus_inspections.id')->map(fn ($id): int => (int) $id)->all());

        $open = [];
        foreach ($candidates as $defectId => $list) {
            foreach ($list as $inspectionId) {
                if (isset($owned[$inspectionId])) {
                    $open[$defectId] = $inspectionId;
                    break;
                }
            }
        }

        return $open;
    }

    /** The Daily Checkout issue page for the apparatus, when the member has no inspection of their own to open. */
    private function issuePageUrl(ApparatusDefect $defect): string
    {
        $slug = $defect->apparatus?->slug;

        return filled($slug) ? '/daily/vehicle-inspections/'.$slug.'/issues' : MyVehicleInspections::getUrl(panel: 'employee');
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [...self::COLUMNS, ...self::OWNERSHIP_COLUMNS];
    }
}
