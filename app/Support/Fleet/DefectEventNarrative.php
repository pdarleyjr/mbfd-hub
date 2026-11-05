<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\ApparatusDefectEvent;

/**
 * The words for the append-only issue history (apparatus_defect_events), shared by the admin
 * inspection page, the Apparatus defects history and anything else that prints a timeline.
 * Who and when are columns on the event; the reason and the status an issue was in before it
 * was closed live in `details` and are printed here so a closure is never a silent row.
 */
final class DefectEventNarrative
{
    private const LABELS = [
        'reported' => 'Issue reported',
        'observed_again' => 'Observed again',
        'appears_corrected' => 'Needs verification: a checkout reported it present',
        'escalated' => 'Escalated to significant damage',
        'acknowledged' => 'Acknowledged',
        'action_updated' => 'Corrective action updated',
        'replacement_available' => 'Replacement available',
        'repair_scheduled' => 'Repair scheduled',
        'resolved' => 'Resolved',
        'dismissed' => DefectNoActionClosure::STATUS_LABEL,
        'reopened' => 'Reopened',
        'correction_verified' => 'Correction verified',
        'ticket_linked' => 'Service ticket linked',
        'impact_classified' => 'Operational impact classified',
        'severity_corrected' => 'Severity corrected',
        'cleared_from_captain5' => 'Sent to Logistics (stays open)',
        'returned_to_captain5' => 'Brought back to Captain 5',
        'merged_into' => 'Merged into another report of the same issue',
        'duplicates_merged' => 'Duplicate reports merged into this issue',
        'owner_transferred' => 'Moved to another queue',
        'correction_reported' => 'Needs verification: a member reported it fixed',
        'member_resolved' => 'Resolved by a member',
        'resolved_by_checkout' => 'Resolved during checkout',
        'resolution_cleared' => 'Cleared from the resolved list',
    ];

    private const OWNER_NAMES = ['captain5' => 'Captain 5', 'logistics' => 'Support Services (Logistics)'];

    private const MEMBER_OUTCOMES = [
        'found' => 'Found',
        'replaced' => 'Replaced',
        'repaired' => 'Repaired',
        'corrected' => 'Fixed',
        'no_longer_deficient' => 'No longer a problem',
    ];

    public static function label(string $eventType): string
    {
        return self::LABELS[$eventType] ?? str($eventType)->replace('_', ' ')->headline()->toString();
    }

    /**
     * Plain lines that explain an event beyond its label: why an issue was closed or reopened,
     * the notes it was resolved with, and what it was before it was closed with no action.
     *
     * @return list<string>
     */
    public static function lines(ApparatusDefectEvent $event): array
    {
        $details = is_array($event->details) ? $event->details : [];
        $text = static fn (string $key): ?string => isset($details[$key]) && is_scalar($details[$key]) && trim((string) $details[$key]) !== ''
            ? trim((string) $details[$key])
            : null;
        $lines = [];

        switch ($event->event_type) {
            case 'dismissed':
                $reason = $text('reason') ?? $text('resolution_notes');
                if ($reason !== null) {
                    $lines[] = 'Reason: '.$reason;
                }
                $before = $text('previous_action_status') ?? ($event->from_status !== null ? (string) $event->from_status : null);
                if ($before !== null && $before !== '') {
                    $lines[] = 'Status before it was closed: '.DefectActionStatus::label($before);
                }
                $lines[] = 'Nothing was repaired or replaced.';
                break;
            case 'reopened':
                if (($reason = $text('reason')) !== null) {
                    $lines[] = 'Reason: '.$reason;
                }
                break;
            case 'severity_corrected':
                $from = $text('from_severity');
                $to = $text('to_severity');
                if ($from !== null && $to !== null) {
                    $lines[] = 'Severity changed from '.DefectSeverityCorrection::label($from).' to '.DefectSeverityCorrection::label($to).'.';
                }
                if (($reason = $text('reason')) !== null) {
                    $lines[] = 'Reason: '.$reason;
                }
                break;
            case 'merged_into':
                if (($survivor = $text('survivor_defect_id')) !== null) {
                    $lines[] = 'Merged into issue #'.$survivor.'. Its reports, messages and members continue there.';
                }
                if (($reason = $text('reason')) !== null) {
                    $lines[] = 'Reason: '.$reason;
                }
                break;
            case 'duplicates_merged':
                $ids = array_values(array_filter(array_map('intval', (array) ($details['duplicate_defect_ids'] ?? []))));
                if ($ids !== []) {
                    $lines[] = 'Merged in: '.implode(', ', array_map(static fn (int $id): string => '#'.$id, $ids)).'.';
                }
                if (($reason = $text('reason')) !== null) {
                    $lines[] = 'Reason: '.$reason;
                }
                break;
            case 'resolved_by_checkout':
                $by = $text('resolved_by');
                $unit = $text('unit');
                if ($by !== null) {
                    $lines[] = 'Resolved by '.$by.($unit !== null ? ' during '.$unit.' checkout' : ' during a checkout').'.';
                }
                $lines[] = 'The checkout reported the item present; this was a routine issue, so it closed itself.';
                break;
            case 'appears_corrected':
                $lines[] = 'This issue is open until someone verifies the correction.';
                break;
            case 'resolution_cleared':
                $lines[] = 'Staff cleared it from the list. The resolution is unchanged.';
                break;
            case 'resolved':
            case 'correction_verified':
                if (($notes = $text('resolution_notes')) !== null) {
                    $lines[] = 'Notes: '.$notes;
                }
                break;
            case 'owner_transferred':
                $to = self::OWNER_NAMES[$text('to') ?? ''] ?? null;
                if ($to !== null) {
                    $lines[] = 'Now handled by '.$to.'.';
                }
                if (($text('basis') ?? '') !== 'manual') {
                    $lines[] = 'Moved automatically after the equipment was classified.';
                }
                break;
            case 'member_resolved':
            case 'correction_reported':
                if (($outcome = self::MEMBER_OUTCOMES[$text('outcome') ?? ''] ?? null) !== null) {
                    $lines[] = 'Member said: '.$outcome.'.';
                }
                if (($note = $text('note')) !== null) {
                    $lines[] = 'Note: '.$note;
                }
                break;
        }

        return $lines;
    }
}
