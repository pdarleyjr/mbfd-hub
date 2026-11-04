<?php

declare(strict_types=1);

namespace App\Services\Activity\Sources;

use App\Filament\Employee\Pages\MyInventoryCorrectionView;
use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent as Event;
use App\Models\Employee;
use App\Models\User;
use App\Services\Activity\ActivitySignal;
use App\Services\Activity\LatestPerGroup;
use App\Services\Activity\MemberActivityItem;
use App\Services\Activity\MemberActivitySource;
use App\Services\Activity\MemberActivityText;
use App\Services\Activity\MemberActivityUpdate;
use App\Services\Inventory\InventoryCorrectionProjection;
use App\Services\Inventory\InventoryCorrectionReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily Checkout inventory corrections the member reported. Only member-visible Logistics events are read;
 * internal notes are filtered in the query. A question from Logistics is the one thing that needs the member.
 */
final class InventoryCorrectionActivitySource implements MemberActivitySource
{
    private const EVENT_WORDS = [
        Event::CLARIFICATION_REQUESTED => 'Support Services has a question for you',
        Event::APPROVED => 'Approved',
        Event::APPLIED => 'Approved and applied to the checklist',
        Event::REJECTED => 'Rejected',
        Event::REVERSED => 'The change was reversed',
        Event::LOGISTICS_REPLIED => 'Support Services replied',
    ];

    public function __construct(private readonly InventoryCorrectionProjection $corrections) {}

    public function items(User $user, ?Employee $employee, CarbonImmutable $since, int $limit): array
    {
        $corrections = $this->corrections->ownedQuery($user)
            ->select(['id', 'apparatus_id', 'reason', 'status', 'target_snapshot', 'proposal', 'decided_at', 'created_at', 'updated_at'])
            ->where(fn (Builder $recent) => $recent
                ->whereIn('status', ApparatusInventoryCorrection::OPEN_STATUSES)
                ->orWhere('created_at', '>=', $since)
                ->orWhere('updated_at', '>=', $since))
            ->with('apparatus:id,designation,name,unit_id')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
        if ($corrections->isEmpty()) {
            return [];
        }

        $signals = $this->signals($user, array_map('intval', $corrections->modelKeys()));

        return $corrections->map(function (ApparatusInventoryCorrection $correction) use ($signals): MemberActivityItem {
            $id = (int) $correction->getKey();
            $signal = $signals[$id] ?? ActivitySignal::none();
            $asks = $correction->status === ApparatusInventoryCorrection::STATUS_CLARIFICATION;
            $snapshot = (array) $correction->target_snapshot;
            $proposal = (array) $correction->proposal;
            $item = trim((string) ($snapshot['name'] ?? ($proposal['name'] ?? 'Unlisted item')));
            $at = MemberActivityText::moment($correction->created_at);
            foreach ([$signal->latest?->at, $correction->decided_at] as $later) {
                if ($later !== null && MemberActivityText::moment($later)->greaterThan($at)) {
                    $at = MemberActivityText::moment($later);
                }
            }

            return new MemberActivityItem(
                key: 'inventory_correction:'.$id,
                category: 'Inventory correction',
                subject: MemberActivityText::unitLabel($correction->apparatus),
                description: $item.' ('.InventoryCorrectionReason::label($correction->reason).')',
                statusLabel: $asks ? 'Question for you' : ApparatusInventoryCorrection::statusLabel($correction->status),
                isOpen: $correction->isOpen(),
                needsResponse: $asks,
                latestUpdate: $signal->latest,
                at: $at,
                url: MyInventoryCorrectionView::getUrl(['correction' => $id], panel: 'employee'),
                unreadAt: $signal->humanAt,
            );
        })->values()->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, ActivitySignal>
     */
    private function signals(User $user, array $ids): array
    {
        $me = $user->getKey();
        $shown = Event::query()
            ->memberVisible()
            ->whereIn('apparatus_inventory_correction_id', $ids)
            ->where('author_context', 'logistics')
            ->where(fn (Builder $author) => $author->whereNull('actor_user_id')->orWhere('actor_user_id', '<>', $me));
        $people = (clone $shown)->whereNotNull('actor_user_id');

        $signals = [];
        foreach (LatestPerGroup::newest($shown, 'apparatus_inventory_correction_id')->get(['id', 'apparatus_inventory_correction_id', 'actor_user_id', 'event_type', 'body', 'created_at']) as $event) {
            $text = filled($event->body) ? (string) $event->body : (self::EVENT_WORDS[$event->event_type] ?? 'Support Services updated this');
            $signals[(int) $event->apparatus_inventory_correction_id] = new ActivitySignal(new MemberActivityUpdate(
                MemberActivityText::excerpt($text),
                MemberActivityText::DEPARTMENT,
                MemberActivityText::moment($event->created_at),
                $event->actor_user_id === null,
            ));
        }
        foreach (LatestPerGroup::newest($people, 'apparatus_inventory_correction_id')->get(['id', 'apparatus_inventory_correction_id', 'created_at']) as $event) {
            $correction = (int) $event->apparatus_inventory_correction_id;
            $signals[$correction] = ($signals[$correction] ?? ActivitySignal::none())
                ->merge(new ActivitySignal(null, MemberActivityText::moment($event->created_at)));
        }

        return $signals;
    }
}
