<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Support\Captain5\EventActorLabel;
use App\Support\Fleet\DefectEventNarrative;
use Carbon\CarbonImmutable;

/**
 * Read-only issue history for the Apparatus defects table: the same append-only
 * `apparatus_defect_events` rows the canonical inspection page shows, newest first, with
 * Captain 5 actions labelled from the authority recorded at the time. A closure also prints
 * its reason and the status the issue was in before it was closed.
 */
final class DefectHistory
{
    private const TIMEZONE = 'America/New_York';

    /**
     * @return list<array{at: string, title: string, actor: string|null, details: list<string>, member_message: string|null, internal_note: string|null}>
     */
    public static function rows(ApparatusDefect $defect, bool $includeInternalNotes): array
    {
        return ApparatusDefectEvent::query()->with('actor:id,name')
            ->where('apparatus_defect_id', $defect->getKey())
            ->orderByDesc('id')->get()
            ->map(static fn (ApparatusDefectEvent $event): array => [
                'at' => CarbonImmutable::parse($event->created_at)->setTimezone(self::TIMEZONE)->format('M j, Y g:i A'),
                'title' => DefectEventNarrative::label((string) $event->event_type),
                'actor' => EventActorLabel::for($event),
                'details' => DefectEventNarrative::lines($event),
                'member_message' => filled($event->member_message) ? (string) $event->member_message : null,
                'internal_note' => $includeInternalNotes && filled($event->internal_note) ? (string) $event->internal_note : null,
            ])->values()->all();
    }
}
