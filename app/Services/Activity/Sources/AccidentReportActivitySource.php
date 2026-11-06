<?php

declare(strict_types=1);

namespace App\Services\Activity\Sources;

use App\Models\AccidentReport;
use App\Models\Employee;
use App\Models\User;
use App\Services\Activity\MemberActivityItem;
use App\Services\Activity\MemberActivitySource;
use App\Services\Activity\MemberActivityText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Accident reports the member is part of: one they started, one about them, or one they must complete as
 * supervisor. Only the report type, reference and status are shown: injury details stay in the report.
 */
final class AccidentReportActivitySource implements MemberActivitySource
{
    public function items(User $user, ?Employee $employee, CarbonImmutable $since, int $limit): array
    {
        $employeeId = MemberActivityText::employeeId($user, $employee);

        return AccidentReport::query()
            ->with(['subject:id,name'])
            ->where(fn (Builder $mine) => $mine
                ->where('created_by_user_id', $user->getKey())
                ->when($employeeId !== null, fn (Builder $q) => $q->orWhere(fn (Builder $in) => $in->whereNotNull('submitted_at')->involvingEmployee($employeeId))))
            ->where(fn (Builder $recent) => $recent->where('updated_at', '>=', $since)->orWhereIn('status', [AccidentReport::STATUS_DRAFT, AccidentReport::STATUS_AWAITING_SUPERVISOR]))
            ->latest('updated_at')->limit($limit)->get()
            ->map(function (AccidentReport $report) use ($employeeId): MemberActivityItem {
                $supervisorTurn = $report->status === AccidentReport::STATUS_AWAITING_SUPERVISOR
                    && $employeeId !== null && (int) $report->supervisor_employee_id === $employeeId;

                return new MemberActivityItem(
                    key: 'accident_report:'.(int) $report->id,
                    category: $report->typeLabel(),
                    subject: $report->reference,
                    description: $report->subject?->name ? 'Employee involved: '.$report->subject->name : 'Accident report',
                    statusLabel: $supervisorTurn ? 'Waiting for your supervisor\'s part' : $report->statusLabel(),
                    isOpen: $report->status !== AccidentReport::STATUS_COMPLETE,
                    needsResponse: $supervisorTurn || $report->status === AccidentReport::STATUS_DRAFT,
                    latestUpdate: null,
                    at: MemberActivityText::moment($report->updated_at),
                    url: '/employee/forms?form=accident-report&report='.$report->ulid,
                    unreadAt: null,
                    ownerLabel: $supervisorTurn ? 'You' : null,
                );
            })->values()->all();
    }
}
