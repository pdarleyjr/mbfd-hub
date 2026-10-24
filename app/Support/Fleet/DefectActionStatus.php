<?php

declare(strict_types=1);

namespace App\Support\Fleet;

/**
 * Lifecycle of a persistent equipment issue (apparatus_defects.action_status).
 * It is the source of truth; the legacy status/resolved columns derive from it.
 */
final class DefectActionStatus
{
    public const REPORTED = 'reported';

    public const ACKNOWLEDGED = 'acknowledged';

    public const ACTION_PENDING = 'action_pending';

    public const REPLACEMENT_AVAILABLE = 'replacement_available';

    public const REPAIR_SCHEDULED = 'repair_scheduled';

    public const RESOLVED = 'resolved';

    public const DISMISSED = 'dismissed';

    /** @var list<string> */
    public const CLOSED = [self::RESOLVED, self::DISMISSED];

    /** @return array<string, string> member-facing labels */
    public static function labels(): array
    {
        return [
            self::REPORTED => 'Awaiting verification',
            self::ACKNOWLEDGED => 'Acknowledged by Logistics',
            self::ACTION_PENDING => 'Action pending',
            self::REPLACEMENT_AVAILABLE => 'Replacement available',
            self::REPAIR_SCHEDULED => 'Repair scheduled',
            self::RESOLVED => 'Resolved',
            self::DISMISSED => DefectNoActionClosure::STATUS_LABEL,
        ];
    }

    /**
     * 'dismissed' is stored as a closed state (so every "is it still open?" query keeps working),
     * but it is NOT a repair. Anything a person reads must tell the two apart.
     */
    public static function isNoAction(?string $status): bool
    {
        return $status === self::DISMISSED;
    }

    /** How a closed issue ended: 'resolved' (fixed), 'closed_no_action', or null while it is open. */
    public static function closureKind(?string $status): ?string
    {
        return match ($status) {
            self::RESOLVED => 'resolved',
            self::DISMISSED => 'closed_no_action',
            default => null,
        };
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function label(?string $status): string
    {
        return self::labels()[$status ?? self::REPORTED] ?? self::labels()[self::REPORTED];
    }

    public static function isClosed(?string $status): bool
    {
        return in_array($status, self::CLOSED, true);
    }

    /** Legacy apparatus_defects.status derived from an action status. */
    public static function legacyStatus(string $status): string
    {
        return match (true) {
            self::isClosed($status) => 'resolved',
            $status === self::REPORTED => 'open',
            default => 'in_progress',
        };
    }
}
