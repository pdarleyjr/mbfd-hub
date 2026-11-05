<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Requests;

use App\Models\Narcotics\NarcoticsCheck;
use App\Models\User;
use App\Services\Dashboards\Support\RecentlyClosedSource;
use App\Services\Narcotics\NarcoticsCheckAccess;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueDetail;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Narcotics checks a member sent for Captain 5 review (narcotics_checks). Read-only here on purpose: approving or
 * rejecting means looking at the seal photos, which only the Captain 5 narcotics review shows (and audits as
 * "viewed"). The row and its panel say what is waiting and link straight to that review.
 *
 * Visibility: NarcoticsCheckAccess::canReview (Captain 5 only, the same rule as the review API). Never on the wall.
 * Open: pending and not archived -> awaiting_response. Closed and shown for the retention window: approved or
 * rejected (decided_at) -> resolved. Severity: medium. Tag: narcotics.
 *
 * @extends RecentlyClosedSource<NarcoticsCheck>
 */
final class NarcoticsCheckSource extends RecentlyClosedSource
{
    private const DECIDED = [NarcoticsCheck::APPROVED, NarcoticsCheck::REJECTED];

    private const REVIEW_PATH = '/captain-5';

    public function __construct(private readonly NarcoticsCheckAccess $access) {}

    public function type(): string
    {
        return 'narcotics_check';
    }

    public function views(): array
    {
        return [DashboardView::MemberRequests, DashboardView::Overview];
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return ! $displayMode && $this->access->canReview($user);
    }

    protected function baseQuery(): Builder
    {
        return NarcoticsCheck::query();
    }

    protected function whereOpen(Builder $query): void
    {
        $query->where('narcotics_checks.status', NarcoticsCheck::PENDING)->whereNull('narcotics_checks.archived_at');
    }

    protected function whereStage(Builder $query, string $stage, OperationalDisplayWindow $window): void
    {
        match ($stage) {
            'awaiting_response' => $this->whereOpen($query),
            'resolved' => $query->whereIn('narcotics_checks.status', self::DECIDED)->where(fn (Builder $q) => self::whereInWindow($q, 'narcotics_checks.decided_at', $window)),
            default => self::nothing($query),
        };
    }

    protected function whereClosedSince(Builder $query, CarbonImmutable $since): void
    {
        $query->whereIn('narcotics_checks.status', self::DECIDED)->where('narcotics_checks.decided_at', '>=', $since);
    }

    protected function whereSeverity(Builder $query, string $severity): void
    {
        if ($severity !== 'medium') {
            self::nothing($query);
        }
    }

    protected function requestTagConstraints(): array
    {
        return ['narcotics' => fn (Builder $q) => $q->whereNotNull('narcotics_checks.id')];
    }

    protected function prepareRows(Builder $query): void
    {
        $query->orderByRaw('CASE WHEN narcotics_checks.status = ? THEN 0 ELSE 1 END', [NarcoticsCheck::PENDING])
            ->orderByDesc('narcotics_checks.submitted_at')->orderByDesc('narcotics_checks.id');
    }

    /** @param NarcoticsCheck $record */
    protected function toIssue(Model $record, bool $displayMode): DashboardIssue
    {
        $pending = $record->isPending() && $record->archived_at === null;
        $unit = $record->rescueUnitLabel();

        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: 'Narcotics check'.($unit !== null ? ' for '.$unit : '').($pending ? ', waiting for review' : ''),
            summary: implode(' · ', array_filter([
                filled($record->seal_number) ? 'Seal '.$record->seal_number : null,
                $record->resubmission_of_id !== null ? 'Sent again after a rejection' : null,
                ! $pending && filled($record->decided_by_name) ? 'Decided by '.$record->decided_by_name : null,
            ])) ?: null,
            severity: 'medium',
            status: (string) $record->status,
            statusLabel: match ($record->status) {
                NarcoticsCheck::APPROVED => 'Approved',
                NarcoticsCheck::REJECTED => 'Rejected',
                default => $pending ? 'Waiting for review' : 'Archived',
            },
            stage: $pending ? 'awaiting_response' : (in_array($record->status, self::DECIDED, true) ? 'resolved' : 'other'),
            tags: ['narcotics'],
            subject: trim((string) $record->submitter_name) ?: null,
            imageKey: null,
            actorLabel: null,
            categoryLabel: 'Narcotics check',
            occurredAt: self::at($pending ? $record->submitted_at : $record->decided_at, $record->updated_at),
            version: self::stamp($record->updated_at),
            sourceUrl: self::REVIEW_PATH,
            groupKey: self::memberGroup($record->submitted_by_user_id, $record->submitted_by_employee_id),
        );
    }

    public function detail(User $user, string $id): ?IssueDetail
    {
        if (! $this->visibleTo($user, false) || preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
            return null;
        }
        $check = NarcoticsCheck::query()->find((int) $id);
        if (! $check instanceof NarcoticsCheck) {
            return null;
        }
        $issue = $this->toIssue($check, false);
        $facts = array_values(array_filter([
            ['label' => 'Unit', 'value' => (string) $check->rescueUnitLabel()],
            ['label' => 'Seal number', 'value' => (string) $check->seal_number],
            ['label' => 'Sent by', 'value' => trim(implode(' · ', array_filter([(string) $check->submitter_rank, (string) $check->submitter_name])))],
            ['label' => 'Sent', 'value' => (string) $check->submitted_at->timezone(OperationalDisplayWindow::TIMEZONE)->format('M j, Y g:i A')],
            ['label' => 'Photos', 'value' => (string) $check->photoCount()],
            ['label' => 'Status', 'value' => $issue->statusLabel],
            ['label' => 'Decided by', 'value' => (string) $check->decided_by_name],
            ['label' => 'Reviewer note', 'value' => (string) $check->decision_note],
        ], static fn (array $fact): bool => trim($fact['value']) !== ''));

        return new IssueDetail($issue, trim((string) $check->member_note) ?: null, $facts, [], [],
            $issue->isOpen()
                ? 'Approve or reject this check in the Captain 5 narcotics review, where the seal photos are. Use "Open full record".'
                : 'This check has been decided. Its record stays in the Captain 5 narcotics archive.');
    }

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        return ActionResult::invalid(['action' => 'Review narcotics checks in the Captain 5 narcotics review, where the photos are.']);
    }
}
