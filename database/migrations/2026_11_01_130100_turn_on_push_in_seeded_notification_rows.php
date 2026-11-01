<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data only (no schema change). The owner wants every member's phone alerts on unless they chose otherwise. Migration
 * 2026_10_30_210000 brought seeded rows up to the defaults, but only for accounts already active that day. Everyone
 * else kept rows a bulk seed wrote with push off, and carried them into their account when they activated (on
 * 2026-11-01: 10 active members and 172 not yet activated, on "Updates on things I submitted"). None of them chose that.
 *
 * - Only turns push ON, and only push. Email and the bell are left as they are.
 * - A member's own choice is never changed, by the same test as the earlier migration: all of a member's rows are left
 *   alone if any was edited after it was created or was created outside a bulk seed.
 * - Skips disabled accounts. Who receives an alert is still decided at send time, and a phone must still be enrolled.
 * - Leaves updated_at alone, so these rows still read as never chosen.
 * - "Every Daily Checkout" and "Fleet operational alerts" are not listed: their phone alerts stay off by policy.
 */
return new class extends Migration
{
    private const BULK_SEED_MINIMUM_ROWS = 5;

    /** @var list<string> The categories with push on by default, frozen on the day this ran. */
    private const PUSH_ON = [
        'member_request_updates', 'department_updates', 'vehicle_inspections', 'vehicle_swap_reports',
        'vehicle_identification_reports', 'apparatus_service_tickets', 'station_inspections', 'station_requests',
        'station_inventory_alerts', 'uniform_requests', 'personnel_equipment_requests', 'workgroup_evaluations',
        'training_assignments', 'hub_support_tickets', 'system_alerts', 'support_services_messages', 'captain5_ems',
        'captain5_assignment_requests', 'command_communications',
    ];

    public function up(): void
    {
        DB::table('user_notification_subscriptions')
            ->whereIn('event_key', self::PUSH_ON)
            ->where('webpush_enabled', false)
            ->whereNotIn('user_id', $this->membersWithAChoice())
            ->whereIn('user_id', fn ($users) => $users->select('id')->from('users')->whereIn('account_status', ['active', 'pending_activation']))
            ->update(['webpush_enabled' => true]);
    }

    /** Intentionally a no-op: an image rollback leaves these values, and each member can change any channel in Settings. */
    public function down(): void {}

    /** @return list<int> */
    private function membersWithAChoice(): array
    {
        $rows = DB::table('user_notification_subscriptions')->get(['user_id', 'created_at', 'updated_at']);
        $rowsPerMinute = $rows->countBy(fn (object $row): string => substr((string) $row->created_at, 0, 16));

        return $rows
            ->filter(fn (object $row): bool => (string) $row->created_at !== (string) $row->updated_at
                || $rowsPerMinute[substr((string) $row->created_at, 0, 16)] < self::BULK_SEED_MINIMUM_ROWS)
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
};
