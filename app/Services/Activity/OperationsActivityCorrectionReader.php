<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Filament\Resources\InventoryCorrectionResource;
use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent;
use App\Models\User;
use App\Services\Dashboards\Support\ApparatusClass;
use App\Support\Fleet\InventoryCorrectionAccess;
use Carbon\CarbonImmutable;

/**
 * Equipment change requests (apparatus_inventory_correction_events) for the Latest activity card.
 * Visible to the same people as the Equipment change request board: the daily inspection reviewers, who may also
 * read the internal-visibility rows. A request needs action while it waits for a decision (status "submitted").
 */
final class OperationsActivityCorrectionReader
{
    private const KINDS = [
        ApparatusInventoryCorrectionEvent::SUBMITTED => 'Equipment change requested',
        ApparatusInventoryCorrectionEvent::CLARIFICATION_REQUESTED => 'Question to member',
        ApparatusInventoryCorrectionEvent::MEMBER_REPLIED => 'Member message',
        ApparatusInventoryCorrectionEvent::LOGISTICS_REPLIED => 'Staff reply',
        ApparatusInventoryCorrectionEvent::INTERNAL_NOTE => 'Internal note',
        ApparatusInventoryCorrectionEvent::APPROVED => 'Approved',
        ApparatusInventoryCorrectionEvent::APPLIED => 'Approved and applied',
        ApparatusInventoryCorrectionEvent::REJECTED => 'Rejected',
        ApparatusInventoryCorrectionEvent::REVERSED => 'Reversed',
    ];

    private const MESSAGES = [
        ApparatusInventoryCorrectionEvent::MEMBER_REPLIED,
        ApparatusInventoryCorrectionEvent::LOGISTICS_REPLIED,
        ApparatusInventoryCorrectionEvent::CLARIFICATION_REQUESTED,
        ApparatusInventoryCorrectionEvent::INTERNAL_NOTE,
    ];

    public static function canRead(User $user): bool
    {
        return InventoryCorrectionAccess::canReview($user);
    }

    /** @return list<OperationsActivityEvent> */
    public function events(User $user, CarbonImmutable $since, int $limit): array
    {
        if (! self::canRead($user)) {
            return [];
        }
        $rows = ApparatusInventoryCorrectionEvent::query()
            ->whereIn('event_type', array_keys(self::KINDS))
            ->where('created_at', '>=', $since)
            ->with(['correction.apparatus:id,unit_id,name,designation', 'actor:id,name,display_name'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        $out = [];
        foreach ($rows as $row) {
            $correction = $row->correction;
            if ($correction instanceof ApparatusInventoryCorrection) {
                $out[] = $this->fromEvent($row, $correction);
            }
        }

        return $out;
    }

    private function fromEvent(ApparatusInventoryCorrectionEvent $event, ApparatusInventoryCorrection $correction): OperationsActivityEvent
    {
        $type = (string) $event->event_type;
        $internal = $event->visibility === ApparatusInventoryCorrectionEvent::VISIBILITY_INTERNAL || $type === ApparatusInventoryCorrectionEvent::INTERNAL_NOTE;

        return new OperationsActivityEvent(
            key: 'apparatus_inventory_correction_events:'.$event->getKey(),
            domain: OperationsActivityEvent::DOMAIN_APPARATUS,
            kind: self::KINDS[$type],
            subject: ApparatusClass::label($correction->apparatus),
            summary: OperationsActivityText::lead(
                InventoryCorrectionResource::itemLabel($correction),
                (string) $event->body,
            ),
            actorLabel: OperationsActivityText::person($event->actor) ?? 'System',
            at: CarbonImmutable::instance($event->created_at),
            statusLabel: ApparatusInventoryCorrection::statusLabel($correction->status),
            actionRequired: in_array($type, [ApparatusInventoryCorrectionEvent::SUBMITTED, ApparatusInventoryCorrectionEvent::MEMBER_REPLIED], true)
                && $correction->status === ApparatusInventoryCorrection::STATUS_SUBMITTED,
            internal: $internal,
            automated: false,
            message: in_array($type, self::MESSAGES, true),
            ownerTag: null,
            url: InventoryCorrectionResource::getUrl('view', ['record' => $correction->getKey()], panel: 'admin'),
        );
    }
}
