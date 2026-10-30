<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Station;

use App\Filament\Resources\StationInspectionResource;
use App\Models\StationInspection;
use App\Models\User;
use App\Services\StationInspectionReviewService;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Station inspections awaiting an officer's review (StationInspectionResource). The actionable
 * record is the review: pending_review → reviewed / needs_follow_up through
 * StationInspectionReviewService, exactly like the resource's "Review / Acknowledge" and
 * "Needs Follow-up" actions. A review decision is immutable, so a reviewed inspection only stays on
 * the board for the rest of the operational day (stage resolved, by reviewed_at).
 *
 * Tag: "facilities". Severity from overall_status: fail → high, needs_attention → medium,
 * pass → low. Visibility: the admin capability gate for StationInspection (admin.stations.view);
 * reviewing needs update (admin.stations.manage), as in the resource.
 */
final class StationInspectionSource extends QueryBackedSource
{
    private const ACTIONS = ['review' => 'reviewed', 'needs_follow_up' => 'needs_follow_up'];

    public function __construct(private readonly StationInspectionReviewService $reviews) {}

    public function type(): string
    {
        return 'station_inspection';
    }

    public function views(): array
    {
        return [DashboardView::StationOperations];
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return $user->can('viewAny', StationInspection::class);
    }

    protected function baseQuery(): Builder
    {
        return StationInspection::query()->active();
    }

    protected function whereOpen(Builder $query): void
    {
        $query->where('review_status', 'pending_review');
    }

    protected function whereStage(Builder $query, string $stage, OperationalDisplayWindow $window): void
    {
        match ($stage) {
            'awaiting_response' => $this->whereOpen($query),
            'resolved' => $query->whereIn('review_status', array_values(self::ACTIONS))
                ->where(fn (Builder $q) => self::whereInWindow($q, 'reviewed_at', $window)),
            default => self::nothing($query),
        };
    }

    protected function whereSeverity(Builder $query, string $severity): void
    {
        match ($severity) {
            'high' => $query->where('overall_status', 'fail'),
            'medium' => $query->where('overall_status', 'needs_attention'),
            'low' => $query->whereNotIn('overall_status', ['fail', 'needs_attention']),
            default => self::nothing($query),
        };
    }

    protected function tagConstraints(): array
    {
        return ['facilities' => fn (Builder $q) => $q->whereNotNull('station_id')];
    }

    protected function prepareRows(Builder $query): void
    {
        $query->select(['id', 'station_id', 'inspector_id', 'inspection_date', 'inspection_type', 'overall_status', 'review_status', 'reviewed_at', 'notes', 'archived_at', 'created_at', 'updated_at'])
            ->with(['station:id,station_number,name', 'inspector:id,name'])
            ->orderByRaw("CASE WHEN review_status = 'pending_review' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE overall_status WHEN 'fail' THEN 0 WHEN 'needs_attention' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @param StationInspection $record */
    protected function toIssue(Model $record, bool $displayMode): DashboardIssue
    {
        $station = $record->station;

        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: trim(ucfirst(str_replace('_', ' ', (string) $record->inspection_type)).' inspection'),
            summary: $displayMode ? null : (str((string) $record->notes)->squish()->limit(160)->toString() ?: null),
            severity: match ($record->overall_status) {
                'fail' => 'high',
                'needs_attention' => 'medium',
                default => 'low',
            },
            status: (string) $record->review_status,
            statusLabel: self::reviewLabel((string) $record->review_status),
            stage: $record->review_status === 'pending_review' ? 'awaiting_response' : 'resolved',
            tags: ['facilities'],
            subject: StationImageKey::label($station),
            imageKey: StationImageKey::for($station),
            actorLabel: $displayMode ? null : ($record->inspector?->name ?: null),
            categoryLabel: 'Inspection: '.ucfirst(str_replace('_', ' ', (string) $record->overall_status)),
            occurredAt: CarbonImmutable::instance($record->created_at),
            version: self::stamp($record->updated_at).'#'.$record->review_status,
            sourceUrl: StationInspectionResource::getUrl('view', ['record' => $record->getKey()], panel: 'admin'),
        );
    }

    public function detail(User $user, string $id): ?IssueDetail
    {
        $inspection = StationInspection::query()->with(['station:id,station_number,name', 'inspector:id,name', 'reviewer:id,name'])->find($id);
        if (! $inspection instanceof StationInspection || ! $this->visibleTo($user, false) || ! $user->can('view', $inspection)) {
            return null;
        }

        $timeline = [[
            'at' => CarbonImmutable::instance($inspection->created_at),
            'actor' => $inspection->inspector?->name,
            'text' => 'Inspection submitted: '.ucfirst(str_replace('_', ' ', (string) $inspection->overall_status)),
        ]];
        if ($inspection->reviewed_at !== null) {
            $timeline[] = [
                'at' => CarbonImmutable::instance($inspection->reviewed_at),
                'actor' => $inspection->reviewer?->name,
                'text' => self::reviewLabel((string) $inspection->review_status).(filled($inspection->review_note) ? ': '.$inspection->review_note : ''),
            ];
        }
        $facts = [
            ['label' => 'Station', 'value' => StationImageKey::label($inspection->station) ?? 'Unknown'],
            ['label' => 'Inspection date', 'value' => $inspection->inspection_date?->format('M j, Y') ?? ''],
            ['label' => 'Type', 'value' => ucfirst(str_replace('_', ' ', (string) $inspection->inspection_type))],
            ['label' => 'Result', 'value' => ucfirst(str_replace('_', ' ', (string) $inspection->overall_status))],
            ['label' => 'Review', 'value' => self::reviewLabel((string) $inspection->review_status)],
            ['label' => 'Inspector', 'value' => (string) ($inspection->inspector?->name ?? 'Unknown')],
        ];

        $reason = match (true) {
            $inspection->isArchived() => 'This inspection is archived.',
            $inspection->review_status !== 'pending_review' => 'This inspection has been reviewed. A review decision is final.',
            ! $this->canReview($user, $inspection) => 'You can view station inspections but not review them.',
            default => null,
        };
        $actions = $reason !== null ? [] : [
            new IssueAction('review', 'Mark reviewed', 'primary', [['name' => 'review_note', 'label' => 'Review note (optional)', 'type' => 'textarea', 'required' => false, 'max' => 2000]]),
            new IssueAction('needs_follow_up', 'Needs follow-up', 'danger', [['name' => 'review_note', 'label' => 'What needs follow-up', 'type' => 'textarea', 'required' => true, 'max' => 2000]]),
        ];

        return new IssueDetail($this->toIssue($inspection, false), $inspection->notes, $facts, $timeline, $actions, $reason);
    }

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        if (! $this->visibleTo($user, false)) {
            return ActionResult::forbidden();
        }
        $status = self::ACTIONS[$action] ?? null;
        if ($status === null) {
            return ActionResult::invalid(['action' => 'This action is not available.']);
        }
        $note = self::text($input['review_note'] ?? '');
        if (mb_strlen($note) > 2000) {
            return ActionResult::invalid(['review_note' => 'Notes can be up to 2,000 characters.']);
        }
        if ($status === 'needs_follow_up' && $note === '') {
            return ActionResult::invalid(['review_note' => 'Describe what needs follow-up.']);
        }

        return DB::transaction(function () use ($user, $id, $status, $note, $expectedVersion): ActionResult {
            $inspection = StationInspection::query()->lockForUpdate()->find($id);
            if (! $inspection instanceof StationInspection || $inspection->isArchived()) {
                return ActionResult::gone('This inspection no longer exists or was archived.');
            }
            if (! $this->canReview($user, $inspection)) {
                return ActionResult::forbidden();
            }
            if ($inspection->review_status !== 'pending_review') {
                $repeat = $inspection->review_status === $status && (int) $inspection->reviewed_by === (int) $user->getKey()
                    && (string) $inspection->review_note === $note;

                return $repeat ? ActionResult::done('Already saved.') : ActionResult::conflict();
            }
            if (self::stamp($inspection->updated_at).'#'.$inspection->review_status !== $expectedVersion) {
                return ActionResult::conflict();
            }

            try {
                $this->reviews->review((int) $inspection->getKey(), $user, $status, $note !== '' ? $note : null);
            } catch (InvalidArgumentException $exception) {
                return ActionResult::invalid(['review_note' => $exception->getMessage()]);
            }

            return ActionResult::done($status === 'reviewed' ? 'Inspection marked reviewed.' : 'Inspection flagged for follow-up.');
        });
    }

    /** The resource's rule: StationInspectionResource::canEdit() is the update ability. */
    private function canReview(User $user, StationInspection $inspection): bool
    {
        return $user->can('update', $inspection);
    }

    private static function reviewLabel(string $status): string
    {
        return match ($status) {
            'pending_review' => 'Awaiting review',
            'reviewed' => 'Reviewed',
            'needs_follow_up' => 'Needs follow-up',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
