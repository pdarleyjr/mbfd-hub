<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Support\Captain5\EventActorLabel;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one sentence that says how a closed equipment issue ended, for every staff screen (Captain 5, Admin board):
 *
 *   resolved by a checkout   "Resolved by Pat Lee during Rescue 5 checkout · Nov 5, 7:42 AM"
 *   resolved by anyone else  "Resolved by Pat Lee · Nov 5, 7:42 AM"   (Mark resolved, staff Resolve, Verify correction)
 *   closed with no action    "Closed, no action required by Pat Lee · Nov 5, 7:42 AM"
 *
 * Read from the newest closing event, in one query for any number of issues. Staff screens only.
 */
final class DefectResolutionLine
{
    public const CLOSING_EVENTS = ['resolved', 'correction_verified', 'member_resolved', 'resolved_by_checkout', 'dismissed'];

    /**
     * @param  iterable<ApparatusDefect>  $defects
     * @return array<int, array{text: string, by: ?string, source: string}> keyed by defect id; open issues are absent
     */
    public static function forDefects(iterable $defects): array
    {
        $closed = [];
        foreach ($defects as $defect) {
            if (DefectActionStatus::isClosed($defect->action_status)) {
                $closed[(int) $defect->getKey()] = $defect;
            }
        }
        if ($closed === []) {
            return [];
        }
        $newest = ApparatusDefectEvent::query()->selectRaw('MAX(id)')->from('apparatus_defect_events')
            ->whereIn('apparatus_defect_id', array_keys($closed))->whereIn('event_type', self::CLOSING_EVENTS)->groupBy('apparatus_defect_id');
        $events = ApparatusDefectEvent::query()->whereIn('id', $newest)->with('actor:id,name,display_name')->get()->keyBy('apparatus_defect_id');

        $lines = [];
        foreach ($closed as $id => $defect) {
            $event = $events->get($id);
            $lines[$id] = $event instanceof ApparatusDefectEvent
                ? self::fromEvent($event, $defect)
                : self::fromDefect($defect);
        }

        return $lines;
    }

    /**
     * The same sentence for a list of many rows without a query per row: from the issue's own columns (the resolver
     * relation loaded, the stored resolution note) and the type of its newest closing event.
     */
    public static function forRow(ApparatusDefect $defect, ?string $closingEvent): ?string
    {
        if (! DefectActionStatus::isClosed($defect->action_status)) {
            return null;
        }
        $user = $defect->relationLoaded('resolvedBy') ? $defect->resolvedBy : null;
        $by = trim((string) ($user?->display_name ?: $user?->name));
        $at = self::moment($defect->resolved_at);
        $suffix = $at !== null ? ' · '.$at : '';
        $notes = trim((string) $defect->resolution_notes);

        return match (true) {
            $defect->isClosedNoAction() => 'Closed, no action required'.($by !== '' ? ' by '.$by : '').$suffix,
            $closingEvent === 'resolved_by_checkout' && $notes !== '' => $notes.$suffix,
            $closingEvent === 'correction_verified' => 'Correction verified'.($by !== '' ? ' by '.$by : '').$suffix,
            default => 'Resolved'.($by !== '' ? ' by '.$by : '').$suffix,
        };
    }

    /** @return array{text: string, by: ?string, source: string} */
    private static function fromEvent(ApparatusDefectEvent $event, ApparatusDefect $defect): array
    {
        $details = is_array($event->details) ? $event->details : [];
        $actor = $event->actor;
        $name = trim((string) ($actor?->display_name ?: $actor?->name));
        $by = $name !== '' ? $name : null;
        $at = self::moment($event->created_at);
        $suffix = $at !== null ? ' · '.$at : '';

        if ($event->event_type === 'resolved_by_checkout') {
            $by = self::scalar($details['resolved_by'] ?? null) ?? $by ?? 'a crew member';
            $unit = self::scalar($details['unit'] ?? null);

            return ['text' => 'Resolved by '.$by.($unit !== null ? ' during '.$unit.' checkout' : ' during a checkout').$suffix, 'by' => $by, 'source' => 'checkout'];
        }
        if ($event->event_type === 'dismissed') {
            return ['text' => 'Closed, no action required'.($by !== null ? ' by '.$by : '').$suffix, 'by' => $by, 'source' => 'no_action'];
        }
        $label = EventActorLabel::for($event, $name) ?? 'staff';
        $member = $event->event_type === 'member_resolved' || ($details['member_resolution'] ?? false) === true;
        $prefix = $event->event_type === 'correction_verified' ? 'Correction verified by ' : 'Resolved by ';

        return ['text' => $prefix.($member ? ($by ?? 'a crew member') : $label).$suffix, 'by' => $by, 'source' => $member ? 'member' : 'staff'];
    }

    /** Closed before events existed: the stored resolver and time, nothing invented. */
    private static function fromDefect(ApparatusDefect $defect): array
    {
        $by = $defect->relationLoaded('resolvedBy') ? trim((string) ($defect->resolvedBy?->display_name ?: $defect->resolvedBy?->name)) : '';
        $at = self::moment($defect->resolved_at);
        $closed = $defect->isClosedNoAction() ? 'Closed, no action required' : 'Resolved';

        return ['text' => $closed.($by !== '' ? ' by '.$by : '').($at !== null ? ' · '.$at : ''), 'by' => $by !== '' ? $by : null, 'source' => 'staff'];
    }

    private static function moment(mixed $at): ?string
    {
        return $at instanceof CarbonInterface ? CarbonImmutable::instance($at)->setTimezone(HubTimezone::NAME)->format('M j, g:i A') : null;
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
