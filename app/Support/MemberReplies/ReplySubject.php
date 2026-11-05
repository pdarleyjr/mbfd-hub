<?php

declare(strict_types=1);

namespace App\Support\MemberReplies;

/**
 * Stable subject keys for member replies (docs/member-replies.md). Stored in `submission_messages.subject_type`
 * and `submission_reply_states.subject_type`; never renamed. The id is the primary key of the named table.
 */
final class ReplySubject
{
    /** apparatus_defects: an equipment / vehicle checkout issue thread (apparatus_issue_messages). */
    public const APPARATUS_DEFECT = 'apparatus_defect';

    /** apparatus_inspections: the general thread of one Daily Checkout (apparatus_issue_messages, defect null). */
    public const APPARATUS_INSPECTION = 'apparatus_inspection';

    /** apparatus_inventory_corrections: equipment change requests (correction events). */
    public const INVENTORY_CORRECTION = 'inventory_correction';

    /** personnel_requests: uniform and personnel equipment / PPE requests (personnel_request_updates). */
    public const PERSONNEL_REQUEST = 'personnel_request';

    /** station_requests: station repair / equipment / fire equipment / big ticket requests (submission_messages). */
    public const STATION_REQUEST = 'station_request';

    /** station_supply_requests: station supply requests (submission_messages). */
    public const STATION_SUPPLY_REQUEST = 'station_supply_request';

    /** apparatus_service_tickets: apparatus service requests (submission_messages). */
    public const APPARATUS_SERVICE_TICKET = 'apparatus_service_ticket';

    /** employee_logistics_threads: employee <-> Logistics messages. */
    public const LOGISTICS_THREAD = 'logistics_thread';

    /** hub_support_tickets: Hub support issues. */
    public const HUB_SUPPORT_TICKET = 'hub_support_ticket';

    /** ems_inventory_requests: Captain 5 / EMS supply requests. */
    public const EMS_INVENTORY_REQUEST = 'ems_inventory_request';

    /** Subjects whose conversation lives in submission_messages. */
    public const GENERIC_THREADS = [self::STATION_REQUEST, self::STATION_SUPPLY_REQUEST, self::APPARATUS_SERVICE_TICKET];

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::APPARATUS_DEFECT, self::APPARATUS_INSPECTION, self::INVENTORY_CORRECTION, self::PERSONNEL_REQUEST,
            self::STATION_REQUEST, self::STATION_SUPPLY_REQUEST, self::APPARATUS_SERVICE_TICKET,
            self::LOGISTICS_THREAD, self::HUB_SUPPORT_TICKET, self::EMS_INVENTORY_REQUEST,
        ];
    }

    public static function isGenericThread(string $type): bool
    {
        return in_array($type, self::GENERIC_THREADS, true);
    }
}
