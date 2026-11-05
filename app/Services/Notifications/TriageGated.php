<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * An OPTIONAL staff alert about one triaged record (an equipment issue, a station request, a supply request).
 * NotificationDispatchService drops it for any recipient who turned that record's priority level off on
 * Notification Settings. Mandatory notifications never implement this.
 */
interface TriageGated
{
    /** @return array{type: string, id: int}|null The triaged record, or null when this instance is not gated. */
    public function triageSubject(): ?array;
}
