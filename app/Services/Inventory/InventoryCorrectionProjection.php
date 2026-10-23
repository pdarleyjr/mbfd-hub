<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Apparatus;
use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent as Event;
use App\Models\User;
use App\Support\Fleet\InventoryCorrectionAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Member-safe views of inventory corrections. Internal notes, other members' identities,
 * private photo paths and Logistics' internal details are never returned from here.
 */
final class InventoryCorrectionProjection
{
    /** Newest open reports shown as checklist markers; keeps the checklist payload bounded. */
    private const MAX_MARKERS = 100;

    /**
     * Open corrections on one apparatus, for the "correction pending review" marker.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingMarkers(Apparatus $apparatus, ?User $viewer): array
    {
        return ApparatusInventoryCorrection::query()
            ->where('apparatus_id', $apparatus->getKey())
            ->open()
            ->orderByDesc('id')
            ->limit(self::MAX_MARKERS)
            ->get(['id', 'compartment_key', 'item_key', 'reason', 'status', 'created_at', 'reporter_user_id', 'reporter_employee_id'])
            ->sortBy('id')
            ->map(function (ApparatusInventoryCorrection $correction) use ($viewer): array {
                // Another member's report is only "something here is under review".
                if (! InventoryCorrectionAccess::owns($viewer, $correction)) {
                    return [
                        'id' => null,
                        'compartment_id' => $correction->compartment_key,
                        'item_id' => $correction->item_key,
                        'reason' => null,
                        'status' => 'pending',
                        'mine' => false,
                    ];
                }

                return [
                    'id' => (int) $correction->getKey(),
                    'compartment_id' => $correction->compartment_key,
                    'item_id' => $correction->item_key,
                    'reason' => $correction->reason,
                    'reason_label' => InventoryCorrectionReason::label($correction->reason),
                    'status' => $correction->status,
                    'reported_at' => $correction->created_at?->toIso8601String(),
                    'mine' => true,
                ];
            })
            ->values()
            ->all();
    }

    /** @return Builder<ApparatusInventoryCorrection> */
    public function ownedQuery(User $user): Builder
    {
        return ApparatusInventoryCorrection::query()->where(function (Builder $query) use ($user): void {
            $query->where('reporter_user_id', $user->getKey());
            if ($user->employee_profile_id !== null) {
                $query->orWhere('reporter_employee_id', $user->employee_profile_id);
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function memberList(User $user, int $limit = 50): array
    {
        return $this->ownedQuery($user)
            ->with('apparatus:id,designation,name,unit_id')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ApparatusInventoryCorrection $correction): array => $this->summary($correction))
            ->all();
    }

    /** @return array<string, mixed> */
    public function summary(ApparatusInventoryCorrection $correction): array
    {
        $snapshot = (array) $correction->target_snapshot;
        $apparatus = $correction->getRelationValue('apparatus') ?? $correction->apparatus()->first();

        return [
            'id' => (int) $correction->getKey(),
            'reference' => $correction->reference(),
            'apparatus' => trim((string) ($apparatus?->designation ?: $apparatus?->name ?: $apparatus?->unit_id)),
            'item' => $snapshot['name'] ?? ($correction->proposal['name'] ?? 'Unlisted item'),
            'compartment' => $snapshot['compartment'] ?? null,
            'reason' => InventoryCorrectionReason::label($correction->reason),
            'status' => $correction->status,
            'status_label' => ApparatusInventoryCorrection::statusLabel($correction->status),
            'needs_reply' => $correction->status === ApparatusInventoryCorrection::STATUS_CLARIFICATION,
            'reported_at' => $correction->created_at,
            'decided_at' => $correction->decided_at,
        ];
    }

    /**
     * What the reporting member may see about their correction: proposal, decision as
     * applied, and the member-visible thread.
     *
     * @return array<string, mixed>
     */
    public function memberDetail(ApparatusInventoryCorrection $correction): array
    {
        $events = $correction->events()->memberVisible()->with('actor:id,name,display_name')->get();
        $decision = is_array($correction->decision) ? $correction->decision : [];
        $applied = $events->last(static fn (Event $event): bool => $event->event_type === Event::APPLIED);

        return [
            ...$this->summary($correction),
            'target' => $correction->target_snapshot,
            'proposal' => $correction->proposal ?? [],
            'note' => $correction->member_note,
            'decision_reason' => $correction->decision_reason,
            'final_operation' => $decision['operation'] ?? null,
            'modified' => (bool) ($decision['modified'] ?? false),
            'applied_to' => $applied?->details['units'] ?? [],
            'thread' => $events->map(static fn (Event $event): array => [
                'id' => (int) $event->getKey(),
                'type' => $event->event_type,
                'from' => $event->author_context === 'logistics' ? 'Logistics' : 'You',
                'body' => $event->body,
                'at' => $event->created_at,
            ])->values()->all(),
            'can_reply' => true,
        ];
    }
}
