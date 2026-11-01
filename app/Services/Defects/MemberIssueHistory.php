<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionConcern;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Support\Captain5\EventActorLabel;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\DefectEventNarrative;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What any member may read about the issues on a physical apparatus (docs/apparatus-issue-history.md section 2).
 *
 * Everything is built from an allowlist of fields. An event's `details`, `internal_note` and `action_details`, a
 * defect's `resolution_notes`, internal messages, a member's reply to staff and the page-only events never reach
 * the output, and no storage path is ever returned. Reporters and the people who acted are named on purpose.
 *
 * The list reads a fixed number of queries however long the histories are; a timeline is loaded one issue at a time.
 */
final class MemberIssueHistory
{
    public const PAGE_SIZE = 20;

    public const NOTE_LIMIT = 20;

    /** What the person reporting wrote. These say what was seen; they are not the department acting on it. */
    private const REPORT_EVENTS = ['reported', 'observed_again', 'appears_corrected'];

    /** Never internal_note: it is not even read. */
    private const EVENT_COLUMNS = [
        'id', 'apparatus_defect_id', 'apparatus_inspection_id', 'actor_user_id', 'event_type',
        'from_status', 'to_status', 'member_message', 'details', 'created_at',
    ];

    public function __construct(
        private readonly DailyCheckoutChecklistResolver $checklists,
        private readonly DefectContinuityService $continuity,
    ) {}

    /** @return array{as_of: string, open: list<array<string, mixed>>, notes: list<array<string, mixed>>, closed_count: int} */
    public function list(Apparatus $apparatus): array
    {
        $open = $this->issues($apparatus)->where('resolved', false)->orderBy('id')->get();

        return [
            'as_of' => CarbonImmutable::now(HubTimezone::NAME)->toIso8601String(),
            'open' => $this->summaries($apparatus, $open),
            'notes' => $this->notes($apparatus),
            'closed_count' => ApparatusDefect::query()->where('apparatus_id', $apparatus->id)->where('resolved', true)->count(),
        ];
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: string|null} */
    public function closed(Apparatus $apparatus, ?string $cursor): array
    {
        $page = $this->issues($apparatus)->where('resolved', true)->orderByDesc('id')
            ->cursorPaginate(self::PAGE_SIZE, ['*'], 'cursor', $cursor);

        return [
            'data' => $this->summaries($apparatus, new Collection($page->items())),
            'next_cursor' => $page->nextCursor()?->encode(),
        ];
    }

    /** @return array{issue: array<string, mixed>, timeline: list<array<string, mixed>>} */
    public function timeline(ApparatusDefect $defect): array
    {
        $apparatus = Apparatus::query()->findOrFail($defect->apparatus_id);
        $one = $this->issues($apparatus)->whereKey($defect->getKey())->get();
        $entries = [...$this->eventEntries($defect), ...$this->messageEntries($defect)];
        usort($entries, static fn (array $a, array $b): int => [$a['sort_at'], $a['sort_kind'], $a['sort_id']] <=> [$b['sort_at'], $b['sort_kind'], $b['sort_id']]);

        return [
            'issue' => $this->summaries($apparatus, $one)[0],
            'timeline' => array_map(static fn (array $entry): array => array_diff_key($entry, array_flip(['sort_at', 'sort_kind', 'sort_id'])), $entries),
        ];
    }

    /** @return Builder<ApparatusDefect> */
    private function issues(Apparatus $apparatus): Builder
    {
        return ApparatusDefect::query()
            ->select('apparatus_defects.*')
            ->selectSub(
                User::query()->selectRaw("COALESCE(NULLIF(users.display_name, ''), users.name)")
                    ->whereColumn('users.id', 'apparatus_defects.first_reported_by_user_id')->limit(1),
                'reporter_name',
            )
            ->where('apparatus_defects.apparatus_id', $apparatus->id)
            ->with(['latestObservation', 'serviceTicket']);
    }

    /**
     * @param  Collection<int, ApparatusDefect>  $defects
     * @return list<array<string, mixed>>
     */
    private function summaries(Apparatus $apparatus, Collection $defects): array
    {
        if ($defects->isEmpty()) {
            return [];
        }
        $ids = $defects->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $index = $this->checklistIndex($apparatus);
        $latest = $this->latestActions($ids);
        $counts = $this->historyCounts($ids);
        $reports = $this->originalReports($ids);

        return $defects->map(function (ApparatusDefect $defect) use ($index, $latest, $counts, $reports): array {
            $location = $this->continuity->currentLocation($index, $defect);
            $status = (string) ($defect->action_status ?: DefectActionStatus::REPORTED);

            return [
                'id' => (int) $defect->id,
                'compartment' => (string) $location['compartment'],
                'item' => (string) $location['item'],
                'compartment_id' => $location['compartment_id'],
                'item_id' => $location['item_id'],
                'issue_type' => (string) $defect->issue_type,
                'severity' => $defect->severity === 'significant' ? 'significant' : 'routine',
                // Staff can edit a defect's notes, so an issue with a recorded report shows what was reported.
                'description' => array_key_exists((int) $defect->id, $reports) ? $reports[(int) $defect->id] : $this->text($defect->notes),
                'reporter_name' => $this->text($defect->getAttribute('reporter_name')),
                'first_reported_at' => $this->at($defect->first_reported_at),
                'action_status' => $status,
                'action_label' => DefectActionStatus::label($status),
                'closure_kind' => DefectActionStatus::closureKind($status),
                'latest_action' => $latest[(int) $defect->id] ?? null,
                'last_observation' => $defect->latestObservation?->observation,
                'last_observed_at' => $this->at($defect->latestObservation?->created_at),
                'service_status' => $defect->serviceTicket?->status,
                'recurrence_of_defect_id' => $defect->recurrence_of_defect_id !== null ? (int) $defect->recurrence_of_defect_id : null,
                'history_count' => $counts[(int) $defect->id] ?? 0,
            ];
        })->all();
    }

    /**
     * The current checklist's names and keys. An unusable checklist, or one that cannot be read right now,
     * places nothing: issues keep the names and keys they were stored with.
     *
     * @return list<array{compartment_id: string, item_id: string, identity: array<string, mixed>}>
     */
    private function checklistIndex(Apparatus $apparatus): array
    {
        try {
            $resolution = $this->checklists->resolve($apparatus);

            return $resolution['usable'] && is_array($resolution['checklist'])
                ? $this->continuity->checklistIndex($resolution['checklist'])
                : [];
        } catch (Throwable $exception) {
            Log::warning('Issue history could not read the current checklist; stored names are used.', [
                'apparatus_id' => $apparatus->id,
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * The newest thing the department did on each issue, in one query: not what a member reported (that is
     * `last_observation`), and never a page-only event.
     *
     * @param  list<int>  $ids
     * @return array<int, array{label: string, actor_name: string|null, at: string|null, text: string|null}>
     */
    private function latestActions(array $ids): array
    {
        $excluded = [...ApparatusDefectEvent::PAGE_ONLY_EVENTS, ...self::REPORT_EVENTS];
        $events = ApparatusDefectEvent::query()
            ->whereIn('id', static fn ($newest) => $newest->selectRaw('MAX(id)')->from('apparatus_defect_events')
                ->whereIn('apparatus_defect_id', $ids)->whereNotIn('event_type', $excluded)->groupBy('apparatus_defect_id'))
            ->with('actor:id,name,display_name')
            ->get(self::EVENT_COLUMNS);

        $latest = [];
        foreach ($events as $event) {
            $latest[(int) $event->apparatus_defect_id] = [
                'label' => DefectEventNarrative::label((string) $event->event_type),
                'actor_name' => $this->actor($event),
                'at' => $this->at($event->created_at),
                'text' => $this->text($event->member_message),
            ];
        }

        return $latest;
    }

    /**
     * What the first report said, for the issues that have one on record, in one query. Older issues from before
     * the event history are absent from the result.
     *
     * @param  list<int>  $ids
     * @return array<int, string|null>
     */
    private function originalReports(array $ids): array
    {
        $reports = [];
        $events = ApparatusDefectEvent::query()
            ->whereIn('apparatus_defect_id', $ids)->where('event_type', 'reported')
            ->orderByDesc('id')
            ->get(['id', 'apparatus_defect_id', 'details']);
        foreach ($events as $event) {
            $details = is_array($event->details) ? $event->details : [];
            $reports[(int) $event->apparatus_defect_id] = $this->text($details['notes'] ?? null);
        }

        return $reports;
    }

    /**
     * Timeline length for each issue: the events and the staff messages members can read, grouped in two queries.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function historyCounts(array $ids): array
    {
        $events = ApparatusDefectEvent::query()->selectRaw('apparatus_defect_id, COUNT(*) AS total')
            ->whereIn('apparatus_defect_id', $ids)->whereNotIn('event_type', ApparatusDefectEvent::PAGE_ONLY_EVENTS)
            ->groupBy('apparatus_defect_id')->pluck('total', 'apparatus_defect_id');
        $messages = ApparatusIssueMessage::query()->selectRaw('apparatus_defect_id, COUNT(*) AS total')
            ->whereIn('apparatus_defect_id', $ids)->memberVisible()->fromStaff()
            ->groupBy('apparatus_defect_id')->pluck('total', 'apparatus_defect_id');

        $counts = [];
        foreach ($ids as $id) {
            $counts[$id] = (int) ($events[$id] ?? 0) + (int) ($messages[$id] ?? 0);
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> the latest informational notes, newest first */
    private function notes(Apparatus $apparatus): array
    {
        return ApparatusInspectionConcern::query()
            ->where('apparatus_id', $apparatus->id)->where('kind', ApparatusInspectionConcern::KIND_NOTE)
            ->whereIn('apparatus_inspection_id', ApparatusInspection::query()->operational()->select('apparatus_inspections.id'))
            ->selectSub(
                ApparatusInspection::query()->select('inspection_reference')
                    ->whereColumn('apparatus_inspections.id', 'apparatus_inspection_concerns.apparatus_inspection_id')->limit(1),
                'inspection_reference',
            )
            ->addSelect(['apparatus_inspection_concerns.id', 'description', 'location', 'reporter_name', 'photo_path', 'created_at'])
            ->orderByDesc('id')->limit(self::NOTE_LIMIT)
            ->get()
            ->map(fn (ApparatusInspectionConcern $note): array => [
                'id' => (int) $note->id,
                'description' => (string) $note->description,
                'location' => $this->text($note->location),
                'reporter_name' => $this->text($note->reporter_name),
                'reported_at' => $this->at($note->created_at),
                'inspection_reference' => $this->text($note->getAttribute('inspection_reference')),
                'photo_url' => $this->has($note->photo_path) ? "/api/public/inspection-concerns/{$note->id}/photo" : null,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function eventEntries(ApparatusDefect $defect): array
    {
        $events = ApparatusDefectEvent::query()
            ->where('apparatus_defect_id', $defect->id)->changingTheIssue()
            ->with('actor:id,name,display_name')
            ->orderBy('created_at')->orderBy('id')
            ->get(self::EVENT_COLUMNS);
        $observations = ApparatusDefectObservation::query()->where('apparatus_defect_id', $defect->id)
            ->get(['id', 'apparatus_inspection_id', 'notes', 'photo_path'])->keyBy('apparatus_inspection_id');

        $entries = [];
        $claimed = [];
        foreach ($events as $event) {
            $observation = null;
            $reported = in_array($event->event_type, self::REPORT_EVENTS, true);
            // One observation exists per issue and inspection: the first report-type event of that inspection owns it.
            if ($reported && $event->apparatus_inspection_id !== null && ! isset($claimed[$event->apparatus_inspection_id])) {
                $observation = $observations->get($event->apparatus_inspection_id);
                $claimed[$event->apparatus_inspection_id] = true;
            }
            $details = is_array($event->details) ? $event->details : [];
            $text = $this->text($event->member_message)
                ?? ($reported ? ($this->text($details['notes'] ?? null) ?? $this->text($observation?->notes)) : null);
            $photo = $this->has($observation?->photo_path)
                ? "/api/public/apparatus-issues/{$defect->id}/observations/{$observation->id}/photo"
                : ($event->event_type === 'reported' && $this->has($defect->getRawOriginal('photo_path')) ? "/api/public/apparatus-issues/{$defect->id}/photo" : null);

            $entries[] = [
                'id' => "event-{$event->id}",
                'kind' => 'event',
                'type' => (string) $event->event_type,
                'label' => DefectEventNarrative::label((string) $event->event_type),
                'lines' => DefectEventNarrative::lines($event),
                'text' => $text,
                'actor_name' => $this->actor($event),
                'at' => $this->at($event->created_at),
                'photo_url' => $photo,
                'sort_at' => (string) $event->created_at?->format('Y-m-d H:i:s.u'),
                'sort_kind' => 0,
                'sort_id' => (int) $event->id,
            ];
        }

        return $entries;
    }

    /** @return list<array<string, mixed>> only what the department wrote to the crew; never a member's reply or an internal note */
    private function messageEntries(ApparatusDefect $defect): array
    {
        return ApparatusIssueMessage::query()
            ->where('apparatus_defect_id', $defect->id)->memberVisible()->fromStaff()
            ->with('author:id,name,display_name')
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'apparatus_defect_id', 'author_user_id', 'author_context', 'body', 'created_at'])
            ->map(function (ApparatusIssueMessage $message): array {
                $author = $message->author;

                return [
                    'id' => "message-{$message->id}",
                    'kind' => 'message',
                    'type' => 'update',
                    'label' => $message->isFromCaptain5() ? 'Update from Captain 5' : 'Update from Logistics',
                    'lines' => [],
                    'text' => (string) $message->body,
                    'actor_name' => $author instanceof User ? $this->text($author->display_name ?: $author->name) : null,
                    'at' => $this->at($message->created_at),
                    'photo_url' => null,
                    'sort_at' => (string) $message->created_at?->format('Y-m-d H:i:s.u'),
                    'sort_kind' => 1,
                    'sort_id' => (int) $message->id,
                ];
            })->all();
    }

    /** The person who acted, as recorded then (a Captain 5 action carries its authority); null when nobody is recorded. */
    private function actor(ApparatusDefectEvent $event): ?string
    {
        $actor = $event->actor;
        $name = $actor instanceof User ? trim((string) ($actor->display_name ?: $actor->name)) : '';

        return EventActorLabel::for($event, $name);
    }

    private function at(?DateTimeInterface $moment): ?string
    {
        return $moment === null ? null : CarbonImmutable::instance($moment)->setTimezone(HubTimezone::NAME)->toIso8601String();
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function has(mixed $path): bool
    {
        return is_string($path) && trim($path) !== '';
    }
}
