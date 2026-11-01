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
        'appears_corrected' => 'Member reported it appears corrected',
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
        'cleared_from_captain5' => 'Cleared from Captain 5 (stays open for Logistics)',
        'returned_to_captain5' => 'Brought back to Captain 5',
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
            case 'resolved':
            case 'correction_verified':
                if (($notes = $text('resolution_notes')) !== null) {
                    $lines[] = 'Notes: '.$notes;
                }
                break;
        }

        return $lines;
    }
}
