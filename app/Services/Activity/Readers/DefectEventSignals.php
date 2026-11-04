<?php

declare(strict_types=1);

namespace App\Services\Activity\Readers;

use App\Models\ApparatusDefectEvent;
use App\Models\User;
use App\Services\Activity\ActivitySignal;
use App\Services\Activity\LatestPerGroup;
use App\Services\Activity\MemberActivityText;
use App\Services\Activity\MemberActivityUpdate;
use App\Support\Fleet\DefectEventNarrative;

/**
 * What the department did on an equipment issue (apparatus_defect_events), for the member who follows it.
 *
 * An allowlist of event types: what the crew reported, internal classifications, ownership moves and
 * the Captain 5 page-only events are never read. `internal_note` and `action_details` are not selected.
 * The member's own two actions on an issue (correction reported, marked fixed) show as "You".
 */
final class DefectEventSignals
{
    /** The department acting on the issue. */
    private const DEPARTMENT_EVENTS = [
        'acknowledged', 'action_updated', 'replacement_available', 'repair_scheduled',
        'resolved', 'dismissed', 'reopened', 'correction_verified',
    ];

    /** A member saying it is fixed: "You" for the one who did it, "A crew member" for everyone else following it. */
    private const OWN_EVENTS = ['correction_reported', 'member_resolved'];

    private const OWN_WORDS = [
        'correction_reported' => 'You reported a correction',
        'member_resolved' => 'You marked this fixed',
    ];

    private const COLUMNS = ['id', 'apparatus_defect_id', 'actor_user_id', 'event_type', 'member_message', 'details', 'created_at'];

    /**
     * @param  list<int>  $ids
     * @return array<int, ActivitySignal> keyed by defect id
     */
    public function forDefects(User $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $me = $user->getKey();

        $shown = ApparatusDefectEvent::query()
            ->whereIn('apparatus_defect_id', $ids)
            ->whereIn('event_type', [...self::DEPARTMENT_EVENTS, ...self::OWN_EVENTS]);
        $people = ApparatusDefectEvent::query()
            ->whereIn('apparatus_defect_id', $ids)
            ->whereIn('event_type', [...self::DEPARTMENT_EVENTS, ...self::OWN_EVENTS])
            ->whereNotNull('actor_user_id')
            ->where('actor_user_id', '<>', $me);

        $signals = [];
        foreach (LatestPerGroup::newest($shown, 'apparatus_defect_id')->get(self::COLUMNS) as $event) {
            $signals[(int) $event->apparatus_defect_id] = new ActivitySignal($this->update($user, $event));
        }
        foreach (LatestPerGroup::newest($people, 'apparatus_defect_id')->get(['id', 'apparatus_defect_id', 'created_at']) as $event) {
            $defect = (int) $event->apparatus_defect_id;
            $signals[$defect] = ($signals[$defect] ?? ActivitySignal::none())
                ->merge(new ActivitySignal(null, MemberActivityText::moment($event->created_at)));
        }

        return $signals;
    }

    private function update(User $user, ApparatusDefectEvent $event): MemberActivityUpdate
    {
        $details = is_array($event->details) ? $event->details : [];
        $mine = $event->actor_user_id !== null && (int) $event->actor_user_id === (int) $user->getKey();
        // Another member saying it is fixed (member_resolved / correction_reported) is not the department speaking.
        $byCrew = ! $mine && (in_array($event->event_type, self::OWN_EVENTS, true) || (bool) ($details['member_resolution'] ?? false));
        $context = is_array($details['actor_context'] ?? null) ? $details['actor_context'] : [];

        $author = match (true) {
            $mine => 'You',
            $byCrew => 'A crew member',
            ($context['role'] ?? null) === 'captain5' => 'Captain 5',
            default => MemberActivityText::DEPARTMENT,
        };
        $message = trim((string) $event->member_message);
        $text = match (true) {
            $message !== '' => $message,
            $mine && isset(self::OWN_WORDS[$event->event_type]) => self::OWN_WORDS[$event->event_type],
            $byCrew && $event->event_type === 'correction_reported' => 'A crew member reported it fixed; awaiting verification',
            $byCrew => 'Marked fixed by a crew member',
            default => DefectEventNarrative::label((string) $event->event_type),
        };

        return new MemberActivityUpdate(
            MemberActivityText::excerpt($text),
            $author,
            MemberActivityText::moment($event->created_at),
            $event->actor_user_id === null,
        );
    }
}
