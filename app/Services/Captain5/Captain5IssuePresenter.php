<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Inspections\IssueMessageService;
use App\Services\Security\Captain5AccessDecision;
use App\Support\Captain5\EventActorLabel;
use App\Support\Fleet\DefectActionStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * JSON shapes for the Captain 5 workspace. Only the data Captain 5 needs: no internal notes for
 * view-only officers, no photo paths, no personnel numbers, no Fleet-only fields.
 */
final class Captain5IssuePresenter
{
    public function __construct(
        private readonly Captain5IssueScope $scope,
        private readonly IssueMessageService $messages,
    ) {}

    /**
     * @param  Collection<int, ApparatusDefect>  $defects  in-scope issues (apparatus + reporter eager-loaded)
     * @return list<array<string, mixed>>
     */
    public function summaries(Collection $defects): array
    {
        if ($defects->isEmpty()) {
            return [];
        }
        $ids = $defects->pluck('id')->all();
        $latestObservation = ApparatusDefectObservation::query()->whereIn('apparatus_defect_id', $ids)
            ->orderBy('id')->get(['apparatus_defect_id', 'observation', 'created_at'])
            ->keyBy('apparatus_defect_id');
        $lastEvent = ApparatusDefectEvent::query()->whereIn('apparatus_defect_id', $ids)
            ->selectRaw('apparatus_defect_id, max(id) as last_id')->groupBy('apparatus_defect_id')
            ->pluck('last_id', 'apparatus_defect_id');

        return $defects->map(fn (ApparatusDefect $defect): array => $this->summary(
            $defect,
            $latestObservation->get($defect->getKey()),
            (int) ($lastEvent[$defect->getKey()] ?? 0),
        ))->values()->all();
    }

    /** @return array<string, mixed> */
    public function summary(ApparatusDefect $defect, ?ApparatusDefectObservation $latest = null, ?int $lastEventId = null): array
    {
        $scope = $this->scope->classify($defect);
        $apparatus = $defect->getRelationValue('apparatus');
        $details = is_array($defect->action_details) ? $defect->action_details : [];
        $expected = isset($details['expected_delivery']) ? (string) $details['expected_delivery'] : null;
        $today = CarbonImmutable::now('America/New_York')->format('Y-m-d');
        $open = ! DefectActionStatus::isClosed($defect->action_status);
        $lastEventId ??= (int) ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->getKey())->max('id');
        $reporter = $defect->getRelationValue('firstReporter');

        return [
            'id' => (int) $defect->getKey(),
            'kind' => $scope->kind,
            'apparatus_id' => (int) $defect->apparatus_id,
            'unit' => $this->unit($apparatus instanceof Apparatus ? $apparatus : null),
            'compartment' => (string) $defect->compartment,
            'item' => (string) $defect->item,
            'issue_type' => (string) ($defect->issue_type ?: 'Missing'),
            'severity' => (string) ($defect->severity ?: 'routine'),
            'action_status' => (string) ($defect->action_status ?: DefectActionStatus::REPORTED),
            'action_label' => DefectActionStatus::label($defect->action_status),
            'open' => $open,
            'details' => array_intersect_key($details, array_flip(Captain5ActorContext::DETAIL_KEYS)),
            'expected_delivery' => $expected,
            'overdue' => $open && $expected !== null && $expected < $today,
            'awaiting_verification' => $open && $latest?->observation === 'appears_corrected',
            'first_reported_at' => $this->iso($defect->first_reported_at ?? $defect->created_at),
            'first_reported_by' => $reporter instanceof User ? $this->name($reporter) : null,
            'last_observed_at' => $this->iso($defect->last_observed_at),
            'resolved_at' => $this->iso($defect->resolved_at),
            'resolution_type' => $defect->resolution_type,
            // How it ended: 'resolved' (fixed) is not 'closed_no_action' (nothing was repaired); the reason shows in history.
            'closure' => DefectActionStatus::closureKind($defect->action_status),
            'closure_reason' => $defect->isClosedNoAction() ? $defect->resolution_notes : null,
            'state_token' => ($defect->action_status ?: DefectActionStatus::REPORTED).':'.$lastEventId,
        ];
    }

    /**
     * Full issue for the detail panel: history, observations, thread and the actions this viewer may take.
     *
     * @return array<string, mixed>
     */
    public function detail(ApparatusDefect $defect, User $viewer, Captain5AccessDecision $decision): array
    {
        $defect->loadMissing(['apparatus', 'firstReporter']);
        $seesInternal = $decision->canAct;
        $events = ApparatusDefectEvent::query()->with('actor:id,name')->where('apparatus_defect_id', $defect->getKey())
            ->orderBy('id')->get()
            ->map(fn (ApparatusDefectEvent $event): array => [
                'id' => (int) $event->getKey(),
                'type' => (string) $event->event_type,
                'from' => $event->from_status,
                'to' => $event->to_status,
                'actor' => EventActorLabel::for($event) ?? 'System',
                'at' => $this->iso($event->created_at),
                'member_message' => $event->member_message,
                'internal_note' => $seesInternal ? $event->internal_note : null,
                'details' => $this->publicDetails($event),
                'inspection_id' => $event->apparatus_inspection_id !== null ? (int) $event->apparatus_inspection_id : null,
            ])->values()->all();

        $observations = ApparatusDefectObservation::query()->where('apparatus_defect_id', $defect->getKey())->orderBy('id')->get();
        $observers = User::query()->whereIn('id', $observations->pluck('actor_user_id')->filter()->unique()->all())->get()->keyBy('id');
        $latest = $observations->last();

        $thread = $this->messages->threadFor($viewer, $defect)->map(fn (ApparatusIssueMessage $message): array => [
            'id' => (int) $message->getKey(),
            'body' => (string) $message->body,
            'visibility' => (string) $message->visibility,
            'author' => $this->messageAuthor($message),
            'context' => (string) $message->author_context,
            'own' => (int) $message->author_user_id === (int) $viewer->getKey(),
            'at' => $this->iso($message->created_at),
        ])->values()->all();

        return [
            ...$this->summary($defect, $latest instanceof ApparatusDefectObservation ? $latest : null),
            'resolution_notes' => $defect->resolution_notes,
            'observations' => $observations->map(fn (ApparatusDefectObservation $o): array => [
                'inspection_id' => (int) $o->apparatus_inspection_id,
                'observation' => (string) $o->observation,
                'reported_status' => $o->reported_status,
                'notes' => $o->notes,
                'by' => isset($observers[$o->actor_user_id]) ? $this->name($observers[$o->actor_user_id]) : null,
                'at' => $this->iso($o->created_at),
            ])->values()->all(),
            'events' => $events,
            'messages' => $thread,
            'can_post_internal' => $seesInternal,
            'can_post' => $this->messages->canPost($viewer, $defect),
            'actions' => $this->allowedActions($defect, $decision, $latest?->observation === 'appears_corrected'),
            'origin_inspection_id' => $defect->first_reported_inspection_id !== null ? (int) $defect->first_reported_inspection_id : null,
        ];
    }

    /** @return list<string> */
    public function allowedActions(ApparatusDefect $defect, Captain5AccessDecision $decision, bool $awaitingVerification): array
    {
        if (! $decision->canAct) {
            return [];
        }
        if (DefectActionStatus::isClosed($defect->action_status)) {
            return ['reopen'];
        }
        $actions = [];
        if ($defect->action_status === DefectActionStatus::REPORTED) {
            $actions[] = 'acknowledge';
        }
        $actions[] = 'ordered';
        if ($defect->action_status === DefectActionStatus::ACTION_PENDING || $defect->action_status === DefectActionStatus::REPLACEMENT_AVAILABLE) {
            $actions[] = 'update_delivery';
        }
        if ($defect->action_status !== DefectActionStatus::REPLACEMENT_AVAILABLE) {
            $actions[] = 'available_for_pickup';
        }
        $actions[] = 'replaced';
        if ($awaitingVerification) {
            $actions[] = 'verify';
        }
        if ($decision->canDismiss) {
            $actions[] = 'dismiss';
        }

        return $actions;
    }

    public function unit(?Apparatus $apparatus): string
    {
        $label = trim((string) ($apparatus?->designation ?: $apparatus?->name));

        return $label !== '' ? $label : 'Unit';
    }

    public function name(User $user): string
    {
        return trim((string) ($user->display_name ?: $user->name)) ?: 'Member';
    }

    public function iso(mixed $moment): ?string
    {
        return $moment instanceof CarbonInterface ? $moment->copy()->utc()->toIso8601String() : null;
    }

    /** @return array<string, mixed> event details without internal bookkeeping */
    private function publicDetails(ApparatusDefectEvent $event): array
    {
        $details = is_array($event->details) ? $event->details : [];
        $keep = array_intersect_key($details, array_flip(['resolution_type', 'resolution_notes', 'reason', 'previous_action_status', 'details', 'changed_details', 'actor_context']));
        if (isset($keep['details']) && is_array($keep['details'])) {
            $keep['details'] = array_intersect_key($keep['details'], array_flip(Captain5ActorContext::DETAIL_KEYS));
        }
        if (isset($keep['actor_context']) && is_array($keep['actor_context'])) {
            $keep['actor_context'] = array_intersect_key($keep['actor_context'], array_flip(['label', 'authority', 'duty_date', 'shift', 'on_duty']));
        }

        return $keep;
    }

    private function messageAuthor(ApparatusIssueMessage $message): string
    {
        $author = $message->getRelationValue('author');
        $name = $author instanceof User ? $this->name($author) : '';

        return match (true) {
            $message->isFromCaptain5() => $name !== '' ? 'Captain 5 · '.$name : 'Captain 5',
            $message->isFromLogistics() => $name !== '' ? 'Logistics · '.$name : 'Logistics',
            default => $name !== '' ? $name : 'Crew member',
        };
    }

    /** Re-export so controllers can compute the token without the action service. */
    public static function stateToken(ApparatusDefect $defect): string
    {
        return DefectCorrectiveActionService::stateToken($defect);
    }
}
