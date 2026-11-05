<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Filament\Resources\DefectResource;
use App\Filament\Support\DefectCorrectiveActions;
use App\Filament\Support\InspectionWorkspace;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\Dashboards\Support\ApparatusClass;
use App\Support\Captain5\EventActorLabel;
use App\Support\Fleet\DefectActionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Equipment-issue history for the Latest activity card: apparatus_defect_events and apparatus_issue_messages.
 *
 * Visible to whoever the Defects list is visible to (Gate viewAny ApparatusDefect, the ApparatusDefectSource rule).
 * Staff-only text (event internal notes, internal-visibility messages) needs the same manage rule the issue panel uses
 * for its timeline, so a view-only account never reads it. A staff reply written together with an event is stored twice
 * (the event's member_message and a member-visible message); only the event is listed. Each query is bounded by the
 * window and a row limit, and everything the rows need is eager loaded.
 */
final class OperationsActivityDefectReader
{
    private const EVENTS = [
        'reported', 'acknowledged', 'action_updated', 'resolved', 'dismissed', 'reopened', 'correction_verified',
        'owner_transferred', 'correction_reported', 'member_resolved', 'appears_corrected', 'resolved_by_checkout',
    ];

    private const CAPTAIN5 = 'captain5';

    public static function canRead(User $user): bool
    {
        return Gate::forUser($user)->allows('viewAny', ApparatusDefect::class);
    }

    /** @return list<OperationsActivityEvent> */
    public function events(User $user, CarbonImmutable $since, int $limit): array
    {
        if (! self::canRead($user)) {
            return [];
        }
        $manage = DefectCorrectiveActions::canManage($user);
        $rows = ApparatusDefectEvent::query()
            ->whereIn('event_type', self::EVENTS)
            ->whereNotIn('event_type', ApparatusDefectEvent::PAGE_ONLY_EVENTS)
            ->where('created_at', '>=', $since)
            ->with(['defect', 'defect.firstReporter:id,name,display_name', 'actor:id,name,display_name'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
        $apparatuses = ApparatusLookup::load($rows->map(
            static fn (ApparatusDefectEvent $row): mixed => $row->defect instanceof ApparatusDefect ? $row->defect->apparatus_id : null,
        ));

        $staffBodies = [];
        $out = [];
        foreach ($rows as $row) {
            if (filled($row->member_message)) {
                $staffBodies[$row->apparatus_defect_id.'|'.trim((string) $row->member_message)] = true;
            }
            $event = $this->fromEvent($row, $manage, $apparatuses);
            if ($event !== null) {
                $out[] = $event;
            }
        }

        return [...$out, ...$this->messages($since, $limit, $manage, $staffBodies)];
    }

    private function fromEvent(ApparatusDefectEvent $event, bool $manage, ApparatusLookup $apparatuses): ?OperationsActivityEvent
    {
        $defect = $event->defect;
        if (! $defect instanceof ApparatusDefect) {
            return null;
        }
        $details = is_array($event->details) ? $event->details : [];
        $type = (string) $event->event_type;
        $memberResolved = $type === 'member_resolved' || ($type === 'resolved' && ! empty($details['member_resolution']));
        $status = (string) ($defect->action_status ?: DefectActionStatus::REPORTED);
        $captain5 = $this->ownedByCaptain5($defect);

        $text = self::detailText($details, $type);
        $member = trim((string) $event->member_message);
        $internalNote = $manage ? trim((string) $event->internal_note) : '';
        $internal = $member === '' && $text === '' && $internalNote !== '';
        $body = $member !== '' ? $member : ($text !== '' ? $text : $internalNote);
        $item = trim((string) $defect->item);

        return new OperationsActivityEvent(
            key: 'apparatus_defect_events:'.$event->getKey(),
            domain: OperationsActivityEvent::DOMAIN_APPARATUS,
            kind: self::kindOf($type, $details, $memberResolved),
            subject: ApparatusClass::label($apparatuses->find($defect->apparatus_id)),
            summary: $type === 'reported'
                ? OperationsActivityText::lead(str((string) $defect->issue_type)->replace('_', ' ')->headline()->toString(), $item)
                : OperationsActivityText::lead($item, $body),
            actorLabel: $this->actor($event, $defect, $type),
            at: CarbonImmutable::instance($event->created_at),
            statusLabel: DefectActionStatus::label($status),
            actionRequired: ! $captain5 && ! DefectActionStatus::isClosed($status) && (
                ($type === 'reported' && $status === DefectActionStatus::REPORTED) || $type === 'correction_reported' || $type === 'appears_corrected'
            ),
            internal: $internal,
            automated: false,
            message: $member !== '',
            ownerTag: $captain5 ? 'Captain 5' : null,
            url: self::urlFor($defect),
        );
    }

    /**
     * @param  array<string, true>  $staffBodies  "defect id|text" of staff messages already shown as an event
     * @return list<OperationsActivityEvent>
     */
    private function messages(CarbonImmutable $since, int $limit, bool $manage, array $staffBodies): array
    {
        $messages = ApparatusIssueMessage::query()
            ->where('created_at', '>=', $since)
            ->when(! $manage, static fn ($query) => $query->where('visibility', ApparatusIssueMessage::VISIBILITY_MEMBER))
            ->with(['author:id,name,display_name', 'defect', 'inspection:id,apparatus_id,review_status'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
        $answered = $this->lastResponseIds($messages->all());
        $apparatuses = ApparatusLookup::load($messages->flatMap(static fn (ApparatusIssueMessage $message): array => [
            $message->defect instanceof ApparatusDefect ? $message->defect->apparatus_id : null,
            $message->inspection instanceof ApparatusInspection ? $message->inspection->apparatus_id : null,
        ]));

        $out = [];
        foreach ($messages as $message) {
            $internal = $message->visibility === ApparatusIssueMessage::VISIBILITY_INTERNAL;
            if (! $internal && $message->isFromStaff() && isset($staffBodies[$message->apparatus_defect_id.'|'.trim((string) $message->body)])) {
                continue;
            }
            $out[] = $this->fromMessage($message, $internal, $answered, $apparatuses);
        }

        return $out;
    }

    /** @param array<string, int> $answered thread key => newest member-visible reply id */
    private function fromMessage(ApparatusIssueMessage $message, bool $internal, array $answered, ApparatusLookup $apparatuses): OperationsActivityEvent
    {
        $defect = $message->defect;
        $apparatus = $apparatuses->find($defect?->apparatus_id) ?? $apparatuses->find($message->inspection?->apparatus_id);
        $context = (string) $message->author_context;
        $automated = $context === ApparatusIssueMessage::CONTEXT_ASSISTANT;
        $fromMember = $context === ApparatusIssueMessage::CONTEXT_MEMBER && ! $internal;
        $captain5 = $defect instanceof ApparatusDefect && $this->ownedByCaptain5($defect);
        $closed = $defect instanceof ApparatusDefect && DefectActionStatus::isClosed($defect->action_status);

        return new OperationsActivityEvent(
            key: 'apparatus_issue_messages:'.$message->getKey(),
            domain: OperationsActivityEvent::DOMAIN_APPARATUS,
            kind: match (true) {
                $internal => 'Internal note',
                $automated => 'Support Services reply',
                $fromMember => 'Member message',
                $context === ApparatusIssueMessage::CONTEXT_CAPTAIN5 => 'Captain 5 reply',
                default => 'Staff reply',
            },
            subject: ApparatusClass::label($apparatus),
            summary: OperationsActivityText::lead($defect instanceof ApparatusDefect ? trim((string) $defect->item) : '', (string) $message->body),
            actorLabel: $automated ? OperationsActivityText::AUTOMATED_ACTOR : (OperationsActivityText::person($message->author) ?? 'Unknown'),
            at: CarbonImmutable::instance($message->created_at),
            statusLabel: $defect instanceof ApparatusDefect ? DefectActionStatus::label($defect->action_status) : 'Checkout message',
            actionRequired: $fromMember && ! $captain5 && ! $closed && (int) $message->getKey() > ($answered[self::threadKey($message)] ?? 0),
            internal: $internal,
            automated: $automated,
            message: true,
            ownerTag: $captain5 ? 'Captain 5' : null,
            url: $defect instanceof ApparatusDefect
                ? self::urlFor($defect)
                : ($message->apparatus_inspection_id !== null ? InspectionWorkspace::url((int) $message->apparatus_inspection_id) : null),
        );
    }

    /**
     * Newest member-visible reply (staff, Captain 5 or the assistant) per thread, for the member messages on this page.
     *
     * @param  list<ApparatusIssueMessage>  $messages
     * @return array<string, int>
     */
    private function lastResponseIds(array $messages): array
    {
        $defects = [];
        $inspections = [];
        foreach ($messages as $message) {
            if ($message->author_context !== ApparatusIssueMessage::CONTEXT_MEMBER) {
                continue;
            }
            if ($message->apparatus_defect_id !== null) {
                $defects[] = $message->apparatus_defect_id;
            } else {
                $inspections[] = $message->apparatus_inspection_id;
            }
        }
        if ($defects === [] && $inspections === []) {
            return [];
        }
        $rows = ApparatusIssueMessage::query()
            ->whereIn('author_context', ApparatusIssueMessage::RESPONSE_CONTEXTS)
            ->where('visibility', ApparatusIssueMessage::VISIBILITY_MEMBER)
            ->where(fn ($query) => $query->whereIn('apparatus_defect_id', array_unique($defects))
                ->orWhere(fn ($inspection) => $inspection->whereNull('apparatus_defect_id')->whereIn('apparatus_inspection_id', array_unique($inspections))))
            ->selectRaw('apparatus_defect_id, apparatus_inspection_id, MAX(id) as last_id')
            ->groupBy('apparatus_defect_id', 'apparatus_inspection_id')
            ->get();

        $last = [];
        foreach ($rows as $row) {
            $key = self::threadKey($row);
            $last[$key] = max($last[$key] ?? 0, (int) $row->getAttribute('last_id'));
        }

        return $last;
    }

    private static function threadKey(ApparatusIssueMessage $message): string
    {
        return $message->apparatus_defect_id !== null ? 'd'.$message->apparatus_defect_id : 'i'.$message->apparatus_inspection_id;
    }

    private function ownedByCaptain5(ApparatusDefect $defect): bool
    {
        return (string) $defect->getAttribute('responsible_owner') === self::CAPTAIN5;
    }

    private function actor(ApparatusDefectEvent $event, ApparatusDefect $defect, string $type): string
    {
        $label = EventActorLabel::for($event, OperationsActivityText::person($event->actor));
        if ($label !== null) {
            return $label;
        }

        return ($type === 'reported' ? OperationsActivityText::person($defect->firstReporter) : null) ?? 'System';
    }

    /** @param array<string, mixed> $details */
    private static function kindOf(string $type, array $details, bool $memberResolved): string
    {
        if ($type === 'resolved_by_checkout') {
            return 'Resolved during checkout';
        }
        if ($memberResolved) {
            return 'Resolved by member';
        }
        if ($type === 'owner_transferred') {
            return ($details['to'] ?? null) === self::CAPTAIN5 ? 'Moved to Captain 5' : 'Moved to Logistics';
        }

        return match ($type) {
            'reported' => 'New issue',
            'acknowledged' => 'Acknowledged',
            'resolved' => 'Resolved',
            'dismissed' => 'Closed, no action',
            'reopened' => 'Reopened',
            'correction_verified' => 'Correction verified',
            'correction_reported', 'appears_corrected' => 'Needs verification',
            default => 'Status update',
        };
    }

    /** @param array<string, mixed> $details */
    private static function detailText(array $details, string $type): string
    {
        if ($type === 'owner_transferred') {
            $from = ($details['from'] ?? null) === self::CAPTAIN5 ? 'Captain 5' : 'Logistics';
            $to = ($details['to'] ?? null) === self::CAPTAIN5 ? 'Captain 5' : 'Logistics';

            return 'From '.$from.' to '.$to;
        }
        foreach (['resolution_notes', 'reason', 'notes', 'note'] as $key) {
            if (isset($details[$key]) && is_scalar($details[$key]) && trim((string) $details[$key]) !== '') {
                return trim((string) $details[$key]);
            }
        }

        return '';
    }

    private static function urlFor(ApparatusDefect $defect): string
    {
        $inspectionId = $defect->first_reported_inspection_id ?? $defect->apparatus_inspection_id ?? $defect->last_observed_inspection_id;

        return $inspectionId !== null
            ? InspectionWorkspace::url((int) $inspectionId, 'issue-'.$defect->getKey())
            : DefectResource::getUrl('edit', ['record' => $defect->getKey()], panel: 'admin');
    }
}
