<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\Captain5\Captain5ActingRequest;
use App\Models\Captain5\Captain5HandoffNote;
use App\Models\User;
use App\Services\DailyRoster\OnDutyOfficerDisplayResolver;
use App\Services\Security\Captain5AccessDecision;
use App\Support\Captain5\DutyCalendar;
use App\Support\Fleet\DefectActionStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Read model for the Captain 5 workspace page: today's duty, Rescue dailies, the open
 * in-scope issue queue with replacement tracking, handoff and acting Captain 5 requests.
 * Everything comes from the shared inspection/issue tables; nothing is copied.
 */
final class Captain5Workspace
{
    /** Far above any realistic queue; reaching it is logged and reported to the page, never silent. */
    private const OPEN_LIMIT = 500;

    private bool $truncated = false;

    private const HISTORY_LIMIT = 200;

    public function __construct(
        private readonly Captain5IssueScope $scope,
        private readonly Captain5IssuePresenter $presenter,
        private readonly DutyCalendar $calendar,
        private readonly OnDutyOfficerDisplayResolver $display,
    ) {}

    /** @return array<string, mixed> */
    public function overview(User $viewer, Captain5AccessDecision $decision): array
    {
        $open = $this->openIssues();
        $summaries = $this->presenter->summaries($open);
        $dutyDate = $decision->onDuty->dutyDate->format('Y-m-d');

        return [
            'access' => $decision->toClientArray(),
            'on_duty' => $this->display->resolve(Captain5ActingRequest::ROLE_CAPTAIN_5)->toArray(),
            'duty_date' => $dutyDate,
            'counts' => $this->counts($summaries),
            'rescue_dailies' => $this->rescueDailies(),
            'issues' => $summaries,
            'issues_truncated' => $this->truncated,
            'handoff' => $this->handoff($dutyDate, $viewer),
            'acting' => $this->acting($viewer, $decision),
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }

    /** @return Collection<int, ApparatusDefect> */
    public function openIssues(): Collection
    {
        [$issues, $this->truncated] = $this->scope->collect(
            $this->scope->candidateQuery()->where('resolved', false)->with('firstReporter'),
            self::OPEN_LIMIT,
        );
        if ($this->truncated) {
            Log::warning('captain5.open_issue_queue_truncated', ['limit' => self::OPEN_LIMIT]);
        }

        return $this->scope->attachApparatus($issues);
    }

    /** @return Collection<int, ApparatusDefect> closed in-scope issues within the history window */
    public function closedIssues(?string $search = null): Collection
    {
        $query = $this->scope->candidateQuery()->where('resolved', true)
            ->where('updated_at', '>=', now()->subDays((int) config('captain5.history_days', 90)))
            ->with('firstReporter');
        if ($search !== null && trim($search) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_substr(trim($search), 0, 60)).'%';
            $query->where(fn ($q) => $q->where('item', 'like', $term)->orWhere('compartment', 'like', $term));
        }

        return $this->scope->attachApparatus($this->scope->collect($query, self::HISTORY_LIMIT)[0]);
    }

    /**
     * @param  list<array<string, mixed>>  $summaries
     * @return array<string, int>
     */
    private function counts(array $summaries): array
    {
        $count = fn (callable $test): int => count(array_filter($summaries, $test));

        return [
            'open' => count($summaries),
            'needs_acknowledgement' => $count(fn (array $s): bool => $s['action_status'] === DefectActionStatus::REPORTED),
            'ordered' => $count(fn (array $s): bool => $s['action_status'] === DefectActionStatus::ACTION_PENDING),
            'ready_for_pickup' => $count(fn (array $s): bool => $s['action_status'] === DefectActionStatus::REPLACEMENT_AVAILABLE),
            'overdue' => $count(fn (array $s): bool => $s['overdue'] === true),
            'awaiting_verification' => $count(fn (array $s): bool => $s['awaiting_verification'] === true),
            'significant' => $count(fn (array $s): bool => $s['severity'] === 'significant'),
            'rescue' => $count(fn (array $s): bool => $s['kind'] === Captain5IssueScope::KIND_RESCUE),
            'medical' => $count(fn (array $s): bool => $s['kind'] === Captain5IssueScope::KIND_MEDICAL),
        ];
    }

    /** @return list<array<string, mixed>> one row per Rescue: today's Daily Checkout status */
    public function rescueDailies(): array
    {
        [$start, $end] = $this->calendar->window($this->calendar->dutyDate());
        $rescues = Apparatus::query()->with('station')->whereIn('id', $this->scope->candidateApparatusIds())->get()
            ->filter(fn (Apparatus $apparatus): bool => in_array($this->scope->checklistType((int) $apparatus->getKey()), Captain5IssueScope::RESCUE_TYPES, true))
            ->sortBy(fn (Apparatus $apparatus): string => sprintf('%d-%s', $apparatus->designation === 'Reserve' ? 1 : 0, (string) $apparatus->designation))
            ->values();
        if ($rescues->isEmpty()) {
            return [];
        }
        $ids = $rescues->pluck('id')->all();
        $inspections = ApparatusInspection::query()->operational() // test submissions never count as a daily
            ->whereIn('apparatus_id', $ids)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->orderByDesc('id')
            ->get(['id', 'apparatus_id', 'operator_name', 'rank', 'created_at', 'completed_at', 'processing_status', 'review_status'])
            ->unique('apparatus_id')->keyBy('apparatus_id');
        $openCounts = ApparatusDefect::query()->whereIn('apparatus_id', $ids)->where('resolved', false)
            ->selectRaw('apparatus_id, count(*) as open_count')->groupBy('apparatus_id')->pluck('open_count', 'apparatus_id');

        return $rescues->map(function (Apparatus $apparatus) use ($inspections, $openCounts): array {
            $inspection = $inspections->get($apparatus->getKey());

            return [
                'apparatus_id' => (int) $apparatus->getKey(),
                'unit' => $this->presenter->unit($apparatus),
                'vehicle_number' => $apparatus->vehicle_number,
                'station' => $apparatus->station?->getAttribute('name'),
                'status' => (string) ($apparatus->getAttribute('status') ?: 'Unknown'),
                'daily' => $inspection === null ? 'not_submitted' : 'submitted',
                'inspection_id' => $inspection !== null ? (int) $inspection->getKey() : null,
                'submitted_at' => $this->presenter->iso($inspection->completed_at ?? $inspection?->created_at),
                'submitted_by' => $inspection !== null ? trim($inspection->rank.' '.$inspection->operator_name) : null,
                'needs_approval' => $inspection?->processing_status === 'held_for_review',
                'open_issues' => (int) ($openCounts[$apparatus->getKey()] ?? 0),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> today's and the previous duty day's handoff notes */
    public function handoff(string $dutyDate, ?User $viewer = null): array
    {
        $previous = $this->calendar->dutyDate()->subDay()->format('Y-m-d');
        $notes = Captain5HandoffNote::query()->with(['author:id,name,display_name', 'acknowledgedBy:id,name,display_name'])
            ->whereIn('duty_date', [$dutyDate, $previous])
            ->orderByDesc('id')->limit(20)->get();

        return [
            'duty_date' => $dutyDate,
            'notes' => $notes->map(fn (Captain5HandoffNote $note): array => [
                'id' => (int) $note->getKey(),
                'duty_date' => $note->duty_date->format('Y-m-d'),
                'author' => $note->author instanceof User ? $this->presenter->name($note->author) : 'Member',
                'own' => $viewer !== null && (int) $note->author_user_id === (int) $viewer->getKey(),
                'authority' => (string) $note->author_authority,
                'body' => (string) $note->body,
                'at' => $this->presenter->iso($note->created_at),
                'acknowledged_by' => $note->acknowledgedBy instanceof User ? $this->presenter->name($note->acknowledgedBy) : null,
                'acknowledged_at' => $this->presenter->iso($note->acknowledged_at),
            ])->values()->all(),
        ];
    }

    /**
     * The "Acting as Captain 5 today?" panel: who is shown now, the viewer's own pending request,
     * and (for Captain 5 administrators) every pending request to approve.
     *
     * @return array<string, mixed>
     */
    public function acting(User $viewer, Captain5AccessDecision $decision): array
    {
        // Same engine and presenter as the 300 page (Captain5ActingService::state), Captain 5 role.
        return app(Captain5ActingService::class)->state($viewer, Captain5ActingRequest::ROLE_CAPTAIN_5);
    }
}
