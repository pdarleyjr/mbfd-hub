<?php

declare(strict_types=1);

namespace App\Support\Captain5;

use App\Models\ApparatusDefectEvent;

/**
 * Who acted on an issue, as recorded at the time: a Captain 5 action carries its authority
 * snapshot in `details.actor_context` ("Captain 5 (acting) · Name"); every other event shows
 * the actor's name as before.
 */
final class EventActorLabel
{
    public static function for(ApparatusDefectEvent $event, ?string $name = null): ?string
    {
        $name ??= $event->relationLoaded('actor') ? $event->actor?->name : null;
        $name = trim((string) $name);
        $context = is_array($event->details) ? ($event->details['actor_context'] ?? null) : null;
        if (! is_array($context) || ($context['role'] ?? null) !== 'captain5') {
            return $name !== '' ? $name : null;
        }
        $label = trim((string) ($context['label'] ?? 'Captain 5')) ?: 'Captain 5';

        return $name !== '' ? $label.' · '.$name : $label;
    }
}
