<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\ApparatusInspectionExceptionResource;
use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusServiceTicketResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\InspectionResource;
use App\Filament\Resources\InventoryCorrectionResource;
use App\Filament\Resources\VehicleSwapRequestResource;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusIssueMessage;
use App\Models\ApparatusServiceTicket;
use App\Models\ApparatusVehicleSwapRequest;
use App\Models\Station;
use App\Models\User;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use App\Services\Inspections\IssueMessageService;
use App\Services\Inventory\InventoryCorrectionReason;
use App\Support\Captain5\EventActorLabel;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\DefectEventNarrative;
use App\Support\Fleet\DefectStatusAttribution;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Read-only view model for the canonical admin inspection workspace
 * (/admin/inspections/{record}). Every section is derived from stored evidence
 * and history; nothing here writes. The submitted checklist is shown exactly as
 * submitted and never reflects the current state of a defect.
 */
final class InspectionWorkspace
{
    public const TIMEZONE = \App\Support\HubTimezone::NAME;

    /** Exception statuses that no longer need a decision. */
    private const CLOSED_EXCEPTION = ['resolved', 'dismissed'];

    private const OBSERVATION_LABELS = [
        'first_reported' => 'Reported for the first time',
        'confirmed_again' => 'Confirmed again',
        'appears_corrected' => 'Member reports it appears corrected',
    ];

    public function __construct(
        private readonly EmployeeInspectionHistoryService $history,
        private readonly IssueMessageService $messages,
    ) {}

    /** URL of the one canonical inspection page, optionally anchored to a section or issue. */
    public static function url(int|ApparatusInspection $inspection, ?string $anchor = null): string
    {
        $id = $inspection instanceof ApparatusInspection ? $inspection->getKey() : $inspection;

        return InspectionResource::getUrl('view', ['record' => $id], panel: 'admin')
            .($anchor !== null && $anchor !== '' ? '#'.ltrim($anchor, '#') : '');
    }

    /**
     * Text and tone of the acceptance status. Always text first, colour second.
     *
     * @return array{label: string, tone: string}
     */
    public static function statusPresentation(ApparatusInspection $inspection): array
    {
        $processing = $inspection->processing_status;
        $review = $inspection->review_status;

        return match (true) {
            $processing === 'held_for_review' => ['label' => 'Needs approval', 'tone' => 'warning'],
            $processing === 'pending_vehicle_swap' => ['label' => 'Held: vehicle swap', 'tone' => 'warning'],
            $processing === 'rejected', $review === 'rejected' => ['label' => 'Rejected', 'tone' => 'danger'],
            $processing === 'accepted' => ['label' => 'Accepted', 'tone' => 'success'],
            $processing === 'accepted_with_exception' => ['label' => 'Follow-up needed', 'tone' => 'warning'],
            $review === 'pending_review' => ['label' => 'Pending review', 'tone' => 'warning'],
            $review === 'approved' => ['label' => 'Approved', 'tone' => 'success'],
            default => ['label' => 'Submitted', 'tone' => 'neutral'],
        };
    }

    /** @return list<array<string, mixed>> */
    private function inventoryCorrections(ApparatusInspection $inspection): array
    {
        return ApparatusInventoryCorrection::query()
            ->where('apparatus_inspection_id', $inspection->getKey())
            ->orderBy('id')
            ->get(['id', 'reason', 'status', 'target_snapshot', 'proposal'])
            ->map(static fn (ApparatusInventoryCorrection $correction): array => [
                'id' => (int) $correction->getKey(),
                'reference' => $correction->reference(),
                'item' => InventoryCorrectionResource::itemLabel($correction),
                'reason' => InventoryCorrectionReason::label($correction->reason),
                'status' => ApparatusInventoryCorrection::statusLabel($correction->status),
                'open' => $correction->isOpen(),
                'url' => InventoryCorrectionResource::getUrl('view', ['record' => $correction->getKey()], panel: 'admin'),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public function build(ApparatusInspection $inspection, ?User $viewer): array
    {
        $inspection->loadMissing([
            'apparatus.station', 'processedApparatus', 'employee', 'reviewEvents.changedByUser',
            'vehicleSwapRequest.decidedBy',
        ]);

        $physicalId = (int) ($inspection->processed_apparatus_id ?? $inspection->apparatus_id);
        $physical = $inspection->processedApparatus ?? $inspection->getRelationValue('apparatus');
        $canReview = $viewer instanceof User && $viewer->can('approve', $inspection);

        $detail = $this->history->detail($inspection);
        $itemPhotos = $this->itemPhotoKeys($detail['compartments']);

        $exceptions = ApparatusInspectionException::query()
            ->where('apparatus_inspection_id', $inspection->getKey())
            ->orderBy('id')
            ->get();

        $holdCards = $this->holdCards($inspection, $exceptions, $physical, $canReview);
        $canManage = DefectCorrectiveActions::canManage($viewer);
        $defects = $this->defectCards($inspection, $physicalId, $this->damageDefectIds($exceptions), $itemPhotos, $viewer, $canReview, $canManage);

        $openHolds = $exceptions->filter(fn (ApparatusInspectionException $e): bool => $this->isOpenHold($e))->count();
        $openIssues = collect($defects['cards'])->where('closed', false)->count();

        return [
            'inspection' => $inspection,
            'status' => self::statusPresentation($inspection),
            'can_review' => $canReview,
            'can_manage' => $canManage,
            'meter_hold_open' => $exceptions->contains(fn (ApparatusInspectionException $e): bool => $this->isOpenHold($e) && in_array($e->field, ['miles', 'engine_hours'], true)),
            'general_thread' => $canReview ? $this->generalThread($inspection, $viewer) : null,
            // Checklist corrections the member reported while doing this inspection (separate from its findings).
            'inventory_corrections' => $canReview ? $this->inventoryCorrections($inspection) : [],
            'header' => $this->header($inspection, $physical, $detail, $viewer, $openHolds, $openIssues),
            'legacy_pending' => $inspection->processing_status === null && $inspection->review_status === 'pending_review',
            'pending_effects' => $this->pendingEffects($inspection),
            'swap' => $this->swapCard($inspection),
            'holds' => $holdCards['cards'],
            'member_flag' => $this->memberFlag($inspection, $exceptions),
            'damage_holds' => $holdCards['damage'],
            'defects' => $defects['cards'],
            'checklist' => $detail,
            'timeline' => $this->timeline($inspection, $defects['ids'], $canReview),
            'history' => $this->vehicleHistory($inspection, $physicalId),
            'apparatus_url' => $physical instanceof Apparatus && ApparatusResource::canView($physical)
                ? ApparatusResource::getUrl('view', ['record' => $physical], panel: 'admin')
                : null,
            'exceptions_url' => ApparatusInspectionExceptionResource::getUrl('index', panel: 'admin'),
            'swaps_url' => VehicleSwapRequestResource::getUrl('index', panel: 'admin'),
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function header(ApparatusInspection $inspection, mixed $physical, array $detail, ?User $viewer, int $openHolds, int $openIssues): array
    {
        $apparatus = $inspection->getRelationValue('apparatus');
        $station = $apparatus instanceof Apparatus ? $apparatus->getRelationValue('station') : null;
        $showEmployeeId = $viewer instanceof User && EmployeeResource::canViewAny();
        $physicalVehicle = $physical instanceof Apparatus ? $physical->vehicle_number : null;

        return [
            'designation' => (string) ($inspection->designation_at_time
                ?: ($apparatus instanceof Apparatus ? ($apparatus->designation ?: $apparatus->name) : null)
                ?: 'Apparatus'),
            'vehicle_number' => $physicalVehicle ?: $inspection->vehicle_number,
            'reference' => $detail['reference'],
            'submitted_at' => $this->eastern($inspection->completed_at ?? $inspection->created_at),
            'member_name' => (string) $inspection->operator_name,
            'member_rank' => (string) $inspection->rank,
            'employee_id' => $showEmployeeId ? $inspection->employee?->employee_id : null,
            'show_employee_id' => $showEmployeeId,
            'shift' => $inspection->shift,
            'station' => $station instanceof Station ? $station->name : null,
            'miles' => $inspection->miles,
            'engine_hours' => $inspection->engine_hours,
            'open_holds' => $openHolds,
            'open_issues' => $openIssues,
            'totals' => $detail['totals'],
        ];
    }

    /**
     * Meter holds and follow-ups (one card per meter exception).
     *
     * @param  Collection<int, ApparatusInspectionException>  $exceptions
     * @return array{cards: list<array<string, mixed>>, damage: list<array<string, mixed>>}
     */
    private function holdCards(ApparatusInspection $inspection, Collection $exceptions, mixed $physical, bool $canReview): array
    {
        $cards = [];
        $damage = [];
        $vehicle = $physical instanceof Apparatus
            ? trim(($physical->designation ?: '').' · Vehicle '.($physical->vehicle_number ?: 'unrecorded'), ' ·')
            : null;

        foreach ($exceptions as $exception) {
            $meta = is_array($exception->metadata) ? $exception->metadata : [];

            if (in_array($exception->field, ['miles', 'engine_hours'], true)) {
                $cards[] = $this->meterCard($inspection, $exception, $meta, $vehicle, $canReview, $physical instanceof Apparatus ? $physical : null);
            } elseif ($exception->field === 'significant_damage') {
                $damage[] = [
                    'id' => (int) $exception->getKey(),
                    'blocking' => $this->isOpenHold($exception),
                    'status_label' => $this->exceptionStatusLabel($exception),
                    'defect_ids' => array_values(array_map('intval', (array) ($meta['defect_ids'] ?? []))),
                ];
            }
        }

        usort($cards, static fn (array $a, array $b): int => [$b['blocking'], $a['id']] <=> [$a['blocking'], $b['id']]);

        return ['cards' => $cards, 'damage' => $damage];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function meterCard(ApparatusInspection $inspection, ApparatusInspectionException $exception, array $meta, ?string $vehicle, bool $canReview, ?Apparatus $physical): array
    {
        $isMiles = $exception->field === 'miles';
        $format = static fn (mixed $value): ?string => $value === null
            ? null
            : ($isMiles ? number_format((float) $value, 0).' mi' : number_format((float) $value, 1).' hrs');

        $previousId = isset($meta['previous_inspection_id']) ? (int) $meta['previous_inspection_id'] : null;
        $previous = $previousId !== null ? ApparatusInspection::query()->find($previousId, ['id', 'inspection_reference', 'completed_at']) : null;
        $difference = ($exception->submitted_value !== null && $exception->authoritative_value !== null)
            ? (float) $exception->submitted_value - (float) $exception->authoritative_value
            : null;

        $decisions = [];
        foreach ($inspection->reviewEvents as $event) {
            $eventMeta = is_array($event->metadata) ? $event->metadata : [];
            if ((int) ($eventMeta['exception_id'] ?? 0) !== (int) $exception->getKey()) {
                continue;
            }
            $decisions[] = [
                'at' => $this->eastern($event->created_at)?->format('M j, Y g:i A'),
                'actor' => (string) ($eventMeta['actor_name'] ?? data_get($event, 'changedByUser.name') ?? 'System'),
                'note' => $event->internal_note,
                'revision' => isset($eventMeta['revision']['value']) ? (string) $eventMeta['revision']['value'] : null,
            ];
        }

        return [
            'id' => (int) $exception->getKey(),
            'anchor' => 'hold-'.$exception->getKey(),
            'field_label' => $isMiles ? 'Odometer miles' : 'Engine hours',
            'reason_label' => str((string) $exception->reason)->replace('_', ' ')->headline()->toString(),
            'blocking' => $this->isOpenHold($exception),
            'is_hold' => (bool) $exception->blocks_acceptance,
            'status_label' => $this->exceptionStatusLabel($exception),
            'current_value' => $format($physical?->getAttribute($isMiles ? 'current_miles' : 'current_engine_hours')),
            'actions' => $canReview && $this->isOpenHold($exception) ? InspectionHoldActions::names() : [],
            'previous_value' => $format($exception->authoritative_value),
            'submitted_value' => $format($exception->submitted_value),
            'loaded_value' => $format($exception->baseline_value),
            'difference' => $difference === null ? null : ($difference < 0 ? '-' : '+').$format(abs($difference)),
            'previous_reference' => $previous?->inspection_reference ?: ($previous !== null ? 'Inspection #'.$previous->getKey() : null),
            'previous_url' => $previous !== null ? self::url($previous) : null,
            'previous_at' => isset($meta['previous_reading_at']) ? $this->eastern($meta['previous_reading_at'])?->format('M j, Y g:i A T') : null,
            'member' => trim($inspection->rank.' '.$inspection->operator_name),
            'vehicle' => $vehicle,
            'explanation' => $this->firstFilled($meta, ['explanation', 'member_explanation', 'note']),
            'decisions' => $decisions,
        ];
    }

    /**
     * @param  Collection<int, ApparatusInspectionException>  $exceptions
     * @return array<string, mixed>|null
     */
    private function memberFlag(ApparatusInspection $inspection, Collection $exceptions): ?array
    {
        $explanation = trim((string) $inspection->review_flag_explanation);
        $hold = $exceptions->first(fn (ApparatusInspectionException $e): bool => $e->field === 'member_review_flag');
        if ($explanation === '' && $hold === null) {
            return null;
        }

        $items = [];
        foreach (is_array($inspection->review_flag_items) ? $inspection->review_flag_items : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $items[] = trim(($item['compartment'] ?? 'Compartment').' / '.($item['item'] ?? $item['item_id'] ?? 'Item'));
        }

        return [
            'explanation' => $explanation !== '' ? $explanation : 'No explanation was recorded.',
            'items' => $items,
            'blocking' => $hold !== null && $this->isOpenHold($hold),
            'status_label' => $hold !== null ? $this->exceptionStatusLabel($hold) : 'Flagged by the member',
        ];
    }

    /**
     * @param  Collection<int, ApparatusInspectionException>  $exceptions
     * @return list<int>
     */
    private function damageDefectIds(Collection $exceptions): array
    {
        return $exceptions->filter(fn (ApparatusInspectionException $e): bool => $e->field === 'significant_damage')
            ->flatMap(fn (ApparatusInspectionException $e): array => array_map('intval', (array) ($e->metadata['defect_ids'] ?? [])))
            ->unique()->values()->all();
    }

    /**
     * One card per defect observed by this inspection, never one per observation.
     *
     * @param  list<int>  $holdDefectIds
     * @param  array<string, string>  $itemPhotos
     * @return array{cards: list<array<string, mixed>>, ids: list<int>}
     */
    private function defectCards(ApparatusInspection $inspection, int $physicalId, array $holdDefectIds, array $itemPhotos, ?User $viewer, bool $canReview, bool $canManage): array
    {
        $id = (int) $inspection->getKey();
        $observations = ApparatusDefectObservation::query()->where('apparatus_inspection_id', $id)->get()->keyBy('apparatus_defect_id');

        $ids = $observations->keys()->map(fn (mixed $key): int => (int) $key)
            ->merge(ApparatusDefect::query()->where('apparatus_inspection_id', $id)->pluck('id')->map(fn (mixed $key): int => (int) $key))
            ->merge($holdDefectIds)
            ->unique()->values();
        if ($ids->isEmpty()) {
            return ['cards' => [], 'ids' => []];
        }

        // A defect id from stored metadata is only trusted when it belongs to this physical vehicle.
        $defects = ApparatusDefect::query()->with('serviceTicket')
            ->whereIn('id', $ids->all())->where('apparatus_id', $physicalId)
            ->orderBy('id')->get();
        if ($defects->isEmpty()) {
            return ['cards' => [], 'ids' => []];
        }

        $trailRows = ApparatusDefectObservation::query()
            ->whereIn('apparatus_defect_id', $defects->modelKeys())->orderBy('id')->get()
            ->groupBy('apparatus_defect_id');
        $inspectionIds = $trailRows->flatten(1)->pluck('apparatus_inspection_id')
            ->merge($defects->pluck('first_reported_inspection_id'))->filter()->unique()->values()->all();
        $inspections = ApparatusInspection::query()->whereIn('id', $inspectionIds)
            ->get(['id', 'inspection_reference', 'completed_at', 'created_at', 'operator_name'])->keyBy('id');
        $userIds = $defects->pluck('first_reported_by_user_id')->filter()->unique()->values()->all();
        $users = $userIds === [] ? collect() : User::query()->whereIn('id', $userIds)->pluck('name', 'id');
        $attribution = DefectStatusAttribution::forDefects($defects);

        $threads = $canReview ? $this->issueThreads($defects->modelKeys()) : null;
        // A recurrence after a no-action closure means that closure was probably wrong; say so.
        $predecessorIds = $defects->pluck('recurrence_of_defect_id')->filter()->unique()->values()->all();
        $closedNoActionPredecessors = $predecessorIds === [] ? [] : ApparatusDefect::query()->whereIn('id', $predecessorIds)
            ->where('action_status', DefectActionStatus::DISMISSED)->pluck('id')->map(fn (mixed $key): int => (int) $key)->all();
        $cards = [];
        foreach ($defects as $defect) {
            $observation = $observations->get($defect->getKey());
            $trail = [];
            foreach ($trailRows->get($defect->getKey(), collect()) as $row) {
                $trailInspection = $inspections->get($row->apparatus_inspection_id);
                $trail[] = [
                    'reference' => $trailInspection?->inspection_reference ?: 'Inspection #'.$row->apparatus_inspection_id,
                    'url' => (int) $row->apparatus_inspection_id === $id ? null : self::url((int) $row->apparatus_inspection_id, 'issue-'.$defect->getKey()),
                    'at' => $this->eastern($trailInspection->completed_at ?? $trailInspection->created_at ?? $row->created_at)?->format('M j, Y g:i A'),
                    'kind' => self::OBSERVATION_LABELS[$row->observation] ?? str((string) $row->observation)->replace('_', ' ')->headline()->toString(),
                    'current' => (int) $row->apparatus_inspection_id === $id,
                ];
            }

            $first = $defect->first_reported_inspection_id !== null ? $inspections->get($defect->first_reported_inspection_id) : null;
            $reportedBy = $users->get($defect->first_reported_by_user_id) ?? $first?->operator_name;
            $photoPath = $this->history->safeImagePath($observation?->photo_path);
            $photoKey = $photoPath !== null
                ? $this->history->imageKey($photoPath)
                : ($itemPhotos[$this->identity($defect->compartment, $defect->item)] ?? null);
            $closed = DefectActionStatus::isClosed($defect->action_status) || (bool) $defect->resolved;
            $ticket = $defect->serviceTicket;
            $ticket = $ticket instanceof ApparatusServiceTicket ? $ticket : null;

            $cards[] = [
                'id' => (int) $defect->getKey(),
                'anchor' => 'issue-'.$defect->getKey(),
                'item' => (string) $defect->item,
                'compartment' => (string) $defect->compartment,
                'issue_label' => str((string) $defect->issue_type)->replace('_', ' ')->headline()->toString(),
                'closed' => $closed,
                'action_status' => (string) ($defect->action_status ?? DefectActionStatus::REPORTED),
                'action_label' => DefectStatusAttribution::labelFor($defect, $attribution),
                // Fixed is green; closed with no action required is neutral (nothing was repaired).
                'action_tone' => $defect->isClosedNoAction() ? 'neutral' : ($closed ? 'success' : ($defect->action_status === DefectActionStatus::REPORTED ? 'warning' : 'info')),
                'closed_no_action' => $defect->isClosedNoAction(),
                'closure' => $closed ? [
                    'kind' => DefectActionStatus::closureKind($defect->action_status),
                    'at' => $this->eastern($defect->resolved_at)?->format('M j, Y g:i A'),
                    'reason' => $defect->isClosedNoAction() ? ($defect->resolution_notes ?: null) : null,
                    'notes' => ! $defect->isClosedNoAction() ? ($defect->resolution_notes ?: null) : null,
                ] : null,
                'significant' => $defect->severity === 'significant',
                'severity_label' => $defect->severity === 'significant' ? 'Significant damage' : 'Routine',
                'first_reported_at' => $this->eastern($defect->first_reported_at ?? $defect->created_at)?->format('M j, Y g:i A'),
                'first_reported_by' => $reportedBy ? (string) $reportedBy : null,
                'first_inspection_reference' => $first?->inspection_reference ?: ($first !== null ? 'Inspection #'.$first->getKey() : null),
                'first_inspection_url' => $first !== null && (int) $first->getKey() !== $id ? self::url($first, 'issue-'.$defect->getKey()) : null,
                'observation' => [
                    'kind' => $observation !== null
                        ? (self::OBSERVATION_LABELS[$observation->observation] ?? str((string) $observation->observation)->replace('_', ' ')->headline()->toString())
                        : self::OBSERVATION_LABELS['first_reported'],
                    'status' => $observation->reported_status ?? str((string) $defect->issue_type)->headline()->toString(),
                    'notes' => filled($observation?->notes) ? (string) $observation->notes : (
                        (int) $defect->apparatus_inspection_id === $id && filled($defect->notes) ? (string) $defect->notes : null
                    ),
                ],
                'trail' => $trail,
                'trail_count' => count($trail),
                'recurrence' => $defect->recurrence_of_defect_id !== null,
                'recurrence_after_no_action' => in_array((int) $defect->recurrence_of_defect_id, $closedNoActionPredecessors, true),
                'ticket' => $ticket !== null ? [
                    'number' => $ticket->ticket_number ?: 'Ticket #'.$ticket->getKey(),
                    'status' => str((string) $ticket->status)->replace('_', ' ')->headline()->toString(),
                    'url' => ApparatusServiceTicketResource::canView($ticket)
                        ? ApparatusServiceTicketResource::getUrl('view', ['record' => $ticket], panel: 'admin')
                        : null,
                ] : null,
                'photo_key' => $photoKey,
                'actions' => $canManage
                    ? DefectCorrectiveActions::applicable($defect, $trailRows->get($defect->getKey(), collect())->last()?->observation === 'appears_corrected')
                    : [],
                'close_blocked' => $canManage && ! $closed ? DefectCorrectiveActions::closeBlockedReason($defect) : null,
                'thread' => $threads === null ? null : $this->messageRows($threads->get($defect->getKey(), collect())),
            ];
        }

        return ['cards' => $cards, 'ids' => $defects->modelKeys()];
    }

    /**
     * Every issue's conversation in one query, oldest first. Only called for reviewers, who may read
     * internal notes too; the caller has already decided that once for the whole page.
     *
     * @param  list<int>  $defectIds
     * @return Collection<(int|string), \Illuminate\Database\Eloquent\Collection<int, ApparatusIssueMessage>>
     */
    private function issueThreads(array $defectIds): Collection
    {
        return ApparatusIssueMessage::query()->with('author:id,name,display_name')
            ->whereIn('apparatus_defect_id', $defectIds)
            ->orderBy('created_at')->orderBy('id')->get()
            ->groupBy('apparatus_defect_id');
    }

    /** @return list<array<string, mixed>> */
    private function generalThread(ApparatusInspection $inspection, User $viewer): array
    {
        try {
            return $this->messageRows($this->messages->threadFor($viewer, $inspection));
        } catch (AuthorizationException) {
            return [];
        }
    }

    /**
     * @param  Collection<int, ApparatusIssueMessage>  $messages
     * @return list<array<string, mixed>>
     */
    private function messageRows(Collection $messages): array
    {
        return $messages->map(fn (ApparatusIssueMessage $message): array => [
            'id' => (int) $message->getKey(),
            'at' => $this->eastern($message->created_at)?->format('M j, Y g:i A'),
            'author' => $this->authorLabel($message),
            'body' => (string) $message->body,
            'internal' => $message->visibility === ApparatusIssueMessage::VISIBILITY_INTERNAL,
            'correction' => $message->corrects_message_id !== null,
        ])->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function pendingEffects(ApparatusInspection $inspection): ?array
    {
        $effects = $inspection->pending_effects;
        if (! is_array($effects) || $inspection->review_status !== 'pending_review') {
            return null;
        }

        return [
            'defects' => is_array($effects['defects'] ?? null) ? $effects['defects'] : [],
            'checklist_v2' => is_array($effects['checklist_v2'] ?? null) ? $effects['checklist_v2'] : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function swapCard(ApparatusInspection $inspection): ?array
    {
        $swap = $inspection->vehicleSwapRequest;
        if (! $swap instanceof ApparatusVehicleSwapRequest) {
            return null;
        }

        return [
            'reported_vehicle' => $swap->reported_vehicle_number,
            'assigned_vehicle' => $swap->assigned_vehicle_number ?: 'none recorded',
            'pending' => $swap->isPending(),
            'status_label' => ApparatusVehicleSwapRequest::statusLabel($swap->status),
            'decided_by' => data_get($swap, 'decidedBy.name') ?? 'Fleet',
            'decided_at' => $swap->decided_at ? $this->eastern($swap->decided_at)?->format('M j, Y g:i A') : null,
            'answer' => ApparatusVehicleSwapRequest::answerLabel($swap->answer),
            'note' => $swap->note,
        ];
    }

    /**
     * Review events, defect events, messages and swap decisions in one chronological list.
     *
     * @param  list<int>  $defectIds
     * @return list<array<string, mixed>>
     */
    private function timeline(ApparatusInspection $inspection, array $defectIds, bool $canReview): array
    {
        $items = [];
        $push = function (mixed $at, string $kind, string $title, array $extra = []) use (&$items): void {
            $moment = $this->eastern($at);
            $items[] = ['sort' => $moment?->format('Y-m-d\TH:i:s.u') ?? '', 'at' => $moment?->format('M j, Y g:i A'), 'kind' => $kind, 'title' => $title]
                + $extra + ['actor' => null, 'body' => null, 'internal' => false, 'evidence' => null, 'anchor' => null];
        };

        $push($inspection->completed_at ?? $inspection->created_at, 'submitted', 'Inspection submitted', [
            'actor' => trim($inspection->rank.' '.$inspection->operator_name) ?: null,
        ]);

        $swap = $inspection->vehicleSwapRequest;
        if ($swap instanceof ApparatusVehicleSwapRequest) {
            $push($swap->created_at, 'swap', 'Vehicle reported: '.$swap->reported_vehicle_number, ['actor' => $swap->reporter_name, 'body' => $swap->note]);
            if (! $swap->isPending()) {
                $push($swap->decided_at ?? $swap->updated_at, 'swap', 'Vehicle swap decision: '.ApparatusVehicleSwapRequest::statusLabel($swap->status), [
                    'actor' => data_get($swap, 'decidedBy.name') ?? 'Fleet', 'body' => $swap->decision_note, 'internal' => filled($swap->decision_note),
                ]);
            }
        }

        foreach ($inspection->reviewEvents as $event) {
            $meta = is_array($event->metadata) ? $event->metadata : [];
            $previous = str((string) $event->previous_status)->replace('_', ' ')->headline()->toString();
            $status = str((string) $event->status)->replace('_', ' ')->headline()->toString();
            $evidence = data_get($meta, 'submitted_effects.checklist_v2');
            $push($event->created_at, 'review', isset($meta['exception_id']) ? 'Meter reading decision: '.$status : 'Review: '.$previous.' to '.$status, [
                'actor' => (string) (data_get($event, 'changedByUser.name') ?? $meta['actor_name'] ?? $meta['reviewer_name'] ?? 'User no longer active'),
                'body' => $event->internal_note,
                'internal' => filled($event->internal_note),
                'evidence' => is_array($evidence) ? $evidence : null,
            ]);
        }

        if ($defectIds !== []) {
            $names = ApparatusDefect::query()->whereIn('id', $defectIds)->pluck('item', 'id');
            foreach (ApparatusDefectEvent::query()->with('actor:id,name')->whereIn('apparatus_defect_id', $defectIds)->orderBy('id')->get() as $event) {
                $label = DefectEventNarrative::label((string) $event->event_type);
                $item = (string) ($names->get($event->apparatus_defect_id) ?? 'equipment issue');
                // A closure prints its reason and prior status here, so the timeline is the authoritative record.
                $body = implode("\n", array_filter([
                    ...DefectEventNarrative::lines($event),
                    filled($event->member_message) ? 'Message to member: '.$event->member_message : null,
                ]));
                $push($event->created_at, 'defect', $label.': '.$item, [
                    'actor' => EventActorLabel::for($event),
                    'body' => $body !== '' ? $body : null,
                    'anchor' => 'issue-'.$event->apparatus_defect_id,
                ]);
                if ($canReview && filled($event->internal_note)) {
                    $push($event->created_at, 'defect', 'Internal note on '.$item, [
                        'actor' => $event->actor?->name, 'body' => $event->internal_note, 'internal' => true,
                        'anchor' => 'issue-'.$event->apparatus_defect_id,
                    ]);
                }
            }
        }

        $messages = ApparatusIssueMessage::query()->with('author:id,name,display_name')
            ->where(function ($query) use ($inspection, $defectIds): void {
                $query->where('apparatus_inspection_id', $inspection->getKey());
                if ($defectIds !== []) {
                    $query->orWhereIn('apparatus_defect_id', $defectIds);
                }
            })
            ->when(! $canReview, fn ($query) => $query->where('visibility', ApparatusIssueMessage::VISIBILITY_MEMBER))
            ->orderBy('created_at')->orderBy('id')->get();
        foreach ($messages as $message) {
            $internal = $message->visibility === ApparatusIssueMessage::VISIBILITY_INTERNAL;
            $push($message->created_at, 'message', $internal ? 'Internal note' : 'Message visible to the member', [
                'actor' => $this->authorLabel($message), 'body' => $message->body, 'internal' => $internal,
                'anchor' => $message->apparatus_defect_id ? 'issue-'.$message->apparatus_defect_id : null,
            ]);
        }

        usort($items, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return $items;
    }

    /** Logistics and Captain 5 replies carry their context (with the staff name for accountability); members their own name. */
    private function authorLabel(ApparatusIssueMessage $message): string
    {
        $name = trim((string) (data_get($message, 'author.display_name') ?: data_get($message, 'author.name')));

        if ($message->isFromCaptain5()) {
            return $name !== '' ? 'Captain 5 · '.$name : 'Captain 5';
        }

        return $message->isFromLogistics()
            ? ($name !== '' ? 'Logistics · '.$name : 'Logistics')
            : ($name !== '' ? $name : 'Member');
    }

    /** @return list<array<string, mixed>> */
    private function vehicleHistory(ApparatusInspection $inspection, int $physicalId): array
    {
        return ApparatusInspection::query()
            ->operational()
            ->whereRaw('COALESCE(processed_apparatus_id, apparatus_id) = ?', [$physicalId])
            ->withCount('defects')
            ->orderByDesc('completed_at')->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (ApparatusInspection $row): array => [
                'id' => (int) $row->getKey(),
                'current' => $row->is($inspection),
                'reference' => $row->inspection_reference ?: 'Inspection #'.$row->getKey(),
                'at' => $this->eastern($row->completed_at ?? $row->created_at)?->format('M j, Y g:i A'),
                'member' => trim($row->rank.' '.$row->operator_name),
                'status' => self::statusPresentation($row),
                'issues' => (int) $row->getAttribute('defects_count'),
                'url' => self::url($row),
            ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $compartments
     * @return array<string, string> identity => photo key
     */
    private function itemPhotoKeys(array $compartments): array
    {
        $keys = [];
        foreach ($compartments as $compartment) {
            foreach ($compartment['items'] as $item) {
                if (! empty($item['photo_key'])) {
                    $keys[$this->identity($compartment['name'], $item['name'])] = (string) $item['photo_key'];
                }
            }
        }

        return $keys;
    }

    private function identity(mixed $compartment, mixed $item): string
    {
        return mb_strtolower(trim((string) $compartment)).'|'.mb_strtolower(trim((string) $item));
    }

    private function isOpenHold(ApparatusInspectionException $exception): bool
    {
        return (bool) $exception->blocks_acceptance && ! in_array($exception->status, self::CLOSED_EXCEPTION, true);
    }

    private function exceptionStatusLabel(ApparatusInspectionException $exception): string
    {
        return match ($exception->status) {
            'open' => $exception->blocks_acceptance ? 'Needs a decision' : 'Follow-up open',
            'revision_requested' => 'Clarification requested',
            'revision_submitted' => 'Clarification received',
            'resolved' => 'Decided',
            'dismissed' => 'Dismissed',
            default => str((string) $exception->status)->replace('_', ' ')->headline()->toString(),
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $keys
     */
    private function firstFilled(array $meta, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($meta[$key]) && is_scalar($meta[$key]) && trim((string) $meta[$key]) !== '') {
                return trim((string) $meta[$key]);
            }
        }

        return null;
    }

    private function eastern(mixed $moment): ?CarbonImmutable
    {
        if ($moment === null || $moment === '') {
            return null;
        }

        return CarbonImmutable::parse($moment)->setTimezone(self::TIMEZONE);
    }
}
