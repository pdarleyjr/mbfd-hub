<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Support\Captain5\EventActorLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who put each equipment issue into its current status, and when, read from the append-only
 * `apparatus_defect_events` history in one query. Status lines then name the person
 * ("Acknowledged by Jane Smith") instead of a department ("Acknowledged by Logistics").
 * Issues with no recorded actor keep the plain department label.
 */
final class DefectStatusAttribution
{
    /**
     * @param  iterable<ApparatusDefect>  $defects
     * @return array<int, array{actor: string|null, at: CarbonImmutable|null}> keyed by defect id
     */
    public static function forDefects(iterable $defects): array
    {
        $byId = Collection::make($defects)->keyBy(static fn (ApparatusDefect $defect): int => (int) $defect->getKey());
        if ($byId->isEmpty()) {
            return [];
        }

        $events = ApparatusDefectEvent::query()
            ->leftJoin('users', 'users.id', '=', 'apparatus_defect_events.actor_user_id')
            ->whereIn('apparatus_defect_events.apparatus_defect_id', $byId->keys()->all())
            ->whereNotNull('apparatus_defect_events.to_status')
            ->where(static fn ($transition) => self::isTransition($transition))
            ->orderBy('apparatus_defect_events.id')
            ->get([
                'apparatus_defect_events.id', 'apparatus_defect_events.apparatus_defect_id', 'apparatus_defect_events.to_status',
                'apparatus_defect_events.details', 'apparatus_defect_events.created_at',
                'users.name as actor_name', 'users.display_name as actor_display_name',
            ]);

        $attribution = [];
        foreach ($events as $event) {
            $defect = $byId->get((int) $event->apparatus_defect_id);
            $current = (string) ($defect?->action_status ?: DefectActionStatus::REPORTED);
            if ($defect === null || $event->to_status !== $current) {
                continue;
            }
            // Later events overwrite earlier ones, so the most recent change into this status wins.
            $name = trim((string) ($event->getAttribute('actor_display_name') ?: $event->getAttribute('actor_name')));
            $attribution[(int) $event->apparatus_defect_id] = [
                'actor' => EventActorLabel::for($event, $name),
                'at' => $event->created_at !== null ? CarbonImmutable::parse($event->created_at) : null,
            ];
        }

        return $attribution;
    }

    /**
     * The same facts as correlated subselects, for a defect query that is already being run
     * (no extra round trip). Read them back with fromSelected().
     *
     * @return array<string, \Illuminate\Database\Eloquent\Builder<ApparatusDefectEvent>>
     */
    public static function subselects(): array
    {
        $latest = static fn (): \Illuminate\Database\Eloquent\Builder => ApparatusDefectEvent::query()
            ->whereColumn('apparatus_defect_events.apparatus_defect_id', 'apparatus_defects.id')
            ->whereColumn('apparatus_defect_events.to_status', 'apparatus_defects.action_status')
            ->where(static fn ($transition) => self::isTransition($transition))
            ->orderByDesc('apparatus_defect_events.id')->limit(1);

        return [
            'status_actor_name' => $latest()->leftJoin('users', 'users.id', '=', 'apparatus_defect_events.actor_user_id')
                ->selectRaw("COALESCE(NULLIF(users.display_name, ''), users.name)"),
            'status_event_at' => $latest()->select('apparatus_defect_events.created_at'),
            'status_event_details' => $latest()->select('apparatus_defect_events.details'),
        ];
    }

    /**
     * @param  iterable<ApparatusDefect>  $defects  loaded with subselects()
     * @return array<int, array{actor: string|null, at: CarbonImmutable|null}>
     */
    public static function fromSelected(iterable $defects): array
    {
        $attribution = [];
        foreach ($defects as $defect) {
            $at = $defect->getAttribute('status_event_at');
            if ($at === null) {
                continue;
            }
            $details = $defect->getAttribute('status_event_details');
            $event = new ApparatusDefectEvent(['details' => is_string($details) ? json_decode($details, true) : $details]);
            $attribution[(int) $defect->getKey()] = [
                'actor' => EventActorLabel::for($event, (string) $defect->getAttribute('status_actor_name')),
                'at' => CarbonImmutable::parse($at),
            ];
        }

        return $attribution;
    }

    /**
     * Only a change INTO a status counts: a note added later ("action_updated" with the same
     * from/to status) must not re-attribute the status to whoever wrote the note.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ApparatusDefectEvent>  $query
     */
    private static function isTransition(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->whereNull('apparatus_defect_events.from_status')
            ->orWhereColumn('apparatus_defect_events.from_status', '<>', 'apparatus_defect_events.to_status');
    }

    /** "Acknowledged by Jane Smith"; the plain status label when nobody is recorded. */
    public static function label(?string $status, ?string $actor): string
    {
        $status = $status ?: DefectActionStatus::REPORTED;
        $base = DefectActionStatus::label($status);
        $actor = trim((string) $actor);
        if ($actor === '') {
            return $base;
        }

        return match ($status) {
            DefectActionStatus::REPORTED => $base.' · reported by '.$actor,
            DefectActionStatus::ACKNOWLEDGED => 'Acknowledged by '.$actor,
            DefectActionStatus::REPAIR_SCHEDULED => 'Repair scheduled by '.$actor,
            DefectActionStatus::RESOLVED => 'Resolved by '.$actor,
            DefectActionStatus::DISMISSED => $base.' · closed by '.$actor,
            default => $base.' · updated by '.$actor,
        };
    }

    /** @param  array<int, array{actor: string|null, at: CarbonImmutable|null}>  $attribution */
    public static function labelFor(ApparatusDefect $defect, array $attribution): string
    {
        return self::label($defect->action_status, $attribution[(int) $defect->getKey()]['actor'] ?? null);
    }
}
