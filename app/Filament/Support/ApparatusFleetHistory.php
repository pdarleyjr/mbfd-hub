<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\ApparatusFleetEvent;
use App\Support\HubTimezone;
use Illuminate\Support\Collection;

/** The append-only fleet event history, in the words Fleet reads on the alert and changeout pages. */
final class ApparatusFleetHistory
{
    private const LABELS = [
        'alert_created' => 'Alert created',
        'alert_updated' => 'Alert edited',
        'alert_notified' => 'Members notified',
        'alert_cancelled' => 'Alert cancelled',
        'alert_closed' => 'Alert closed',
        'alert_completed' => 'Alert completed by a changeout',
        'committed' => 'Unit moved to the new truck',
        'handoff_only' => 'What the crew took and left recorded, the unit had already moved',
        'acknowledged' => 'Acknowledged',
    ];

    /**
     * @param  Collection<int, ApparatusFleetEvent>  $events
     * @return list<string>
     */
    public static function lines(Collection $events): array
    {
        return $events->map(static fn (ApparatusFleetEvent $event): string => self::line($event))->values()->all();
    }

    private static function line(ApparatusFleetEvent $event): string
    {
        $when = $event->created_at->setTimezone(HubTimezone::NAME)->format('M j, Y g:i A');
        $who = $event->actor_name ?: 'the system';
        $note = $event->data['note'] ?? null;
        $changed = array_keys((array) ($event->data['changed'] ?? []));

        return trim($when.': '.self::label($event->event).' by '.$who.'.'
            .($changed === [] ? '' : ' Changed: '.implode(', ', array_map(static fn (string $field): string => str_replace('_', ' ', $field), $changed)).'.')
            .(is_string($note) && $note !== '' ? ' Note: '.$note : ''));
    }

    private static function label(string $event): string
    {
        return self::LABELS[$event] ?? str($event)->replace('_', ' ')->ucfirst()->toString();
    }
}
