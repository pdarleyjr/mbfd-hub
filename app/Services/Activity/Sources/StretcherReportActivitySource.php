<?php

declare(strict_types=1);

namespace App\Services\Activity\Sources;

use App\Models\Employee;
use App\Models\User;
use App\Services\Activity\ActivitySignal;
use App\Services\Activity\MemberActivityItem;
use App\Services\Activity\MemberActivitySource;
use App\Services\Activity\MemberActivityText;
use App\Services\Activity\Readers\DefectEventSignals;
use App\Services\Activity\Readers\IssueMessageSignals;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stretcher problems the member reported on their own (stretcher_issue_reports). Their state and every update
 * come from the Captain 5 issue the report is attached to. The table belongs to another workstream, so the
 * source quietly returns nothing until it exists.
 */
final class StretcherReportActivitySource implements MemberActivitySource
{
    private const PROBLEMS = [
        'loading' => 'Loading or lifting',
        'armrest' => 'Armrest or rail',
        'battery' => 'Battery',
        'charger' => 'Charger',
        'restraint' => 'Straps or restraints',
        'mechanical' => 'Mechanical problem',
        'other' => 'Other problem',
    ];

    public function __construct(
        private readonly IssueMessageSignals $messages,
        private readonly DefectEventSignals $events,
    ) {}

    public function items(User $user, ?Employee $employee, CarbonImmutable $since, int $limit): array
    {
        $employeeId = MemberActivityText::employeeId($user, $employee);

        $reports = DB::table('stretcher_issue_reports as r')
            ->leftJoin('apparatus_defects as d', 'd.id', '=', 'r.apparatus_defect_id')
            ->leftJoin('apparatuses as a', 'a.id', '=', 'r.apparatus_id')
            ->where(fn (Builder $mine) => $mine
                ->where('r.reporter_user_id', $user->getKey())
                ->when($employeeId !== null, fn (Builder $q) => $q->orWhere('r.reporter_employee_id', $employeeId)))
            ->where(fn (Builder $recent) => $recent->where('r.created_at', '>=', $since)->orWhere('d.resolved', false))
            ->orderByDesc('r.id')
            ->limit($limit)
            ->get([
                'r.id', 'r.problem', 'r.description', 'r.unusable', 'r.created_at', 'r.apparatus_defect_id',
                'd.resolved as defect_resolved', 'd.resolved_at as defect_resolved_at', 'd.action_status',
                'a.designation', 'a.name as apparatus_name', 'a.slug',
            ]);
        if ($reports->isEmpty()) {
            return [];
        }

        $defectIds = $reports->pluck('apparatus_defect_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $signals = $this->messages->forTargets($user, 'apparatus_defect_id', $defectIds);
        foreach ($this->events->forDefects($user, $defectIds) as $id => $signal) {
            $signals[$id] = ($signals[$id] ?? ActivitySignal::none())->merge($signal);
        }
        $reported = $this->correctionsReported($defectIds);

        return $reports->map(function (object $report) use ($signals, $reported): MemberActivityItem {
            $defectId = $report->apparatus_defect_id !== null ? (int) $report->apparatus_defect_id : null;
            $signal = $defectId !== null ? ($signals[$defectId] ?? ActivitySignal::none()) : ActivitySignal::none();
            $linked = $defectId !== null && $report->defect_resolved !== null;
            $closed = $linked && (bool) $report->defect_resolved;
            $at = MemberActivityText::moment($report->created_at);
            foreach ([$signal->latest?->at, $report->defect_resolved_at] as $later) {
                if ($later !== null && MemberActivityText::moment($later)->greaterThan($at)) {
                    $at = MemberActivityText::moment($later);
                }
            }
            $label = self::PROBLEMS[$report->problem] ?? 'Stretcher problem';

            return new MemberActivityItem(
                key: 'stretcher_report:'.(int) $report->id,
                category: 'Stretcher report',
                subject: trim((string) ($report->designation ?: ($report->apparatus_name ?: 'Apparatus'))),
                description: MemberActivityText::excerpt($label.': '.$report->description),
                statusLabel: $linked
                    ? MemberActivityText::defectStatus($closed, $report->action_status, isset($reported[$defectId]))
                    : 'Received',
                isOpen: $linked && ! $closed,
                needsResponse: false,
                latestUpdate: $signal->latest,
                at: $at,
                url: filled($report->slug) ? '/daily/vehicle-inspections/'.$report->slug.'/issues' : '/daily',
                unreadAt: $signal->humanAt,
                ownerLabel: $linked && ! $closed ? 'Captain 5' : null,
            );
        })->values()->all();
    }

    /**
     * @param  list<int>  $defectIds
     * @return array<int, true> issues where a member has reported a correction that is waiting to be verified
     */
    private function correctionsReported(array $defectIds): array
    {
        if ($defectIds === []) {
            return [];
        }

        return DB::table('apparatus_defects')->whereIn('id', $defectIds)->whereNotNull('correction_reported_at')
            ->pluck('id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
    }
}
