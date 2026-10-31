<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data only (no schema change). Most stored notification rows were never a member's choice: bulk migrations
 * seeded them from the defaults of the day (on 2026-10-30, 1,053 of 1,067 rows had never been changed). A member
 * with a seeded row therefore never received the current defaults (see NotificationPresetPolicy), so a device
 * could turn on alerts and still get almost none. This brings those seeded rows up to the defaults.
 *
 * A member's own choice is never changed. A member has made a choice when any of their rows was edited after it
 * was created, or was created outside a bulk seed: the settings page only creates a row that differs from the
 * default, one member at a time. All of that member's rows are then left exactly as they are.
 *
 * - Only turns channels ON. Nothing is ever turned off.
 * - Only touches channels the category supports (the list below is the policy frozen on the day it ran).
 * - Creates no rows: members without a saved choice already get these defaults.
 * - Skips accounts that are not active. Who actually receives an alert is still decided at send time
 *   (eligibility, an enrolled phone, a city email on file), so this changes none of that.
 * - "Every Daily Checkout as it's submitted" is not listed: its rows were copied from members' own inspection
 *   alert choices (2026_10_24_100000), so they are choices.
 */
return new class extends Migration
{
    /** Rows created in the same minute as fewer rows than this were not a bulk seed. */
    private const BULK_SEED_MINIMUM_ROWS = 5;

    /** @var array<string, list<string>> category key => channels to turn on */
    private const PRESETS = [
        'member_request_updates' => ['database', 'webpush', 'email'],
        'department_updates' => ['database', 'webpush'],
        'vehicle_inspections' => ['database', 'webpush'],
        'vehicle_swap_reports' => ['database', 'webpush', 'email'],
        'vehicle_identification_reports' => ['database', 'webpush', 'email'],
        'apparatus_service_tickets' => ['database', 'webpush'],
        'vehicle_maintenance_alerts' => ['database'],
        'station_inspections' => ['database', 'webpush'],
        'station_requests' => ['database', 'webpush'],
        'station_inventory_alerts' => ['database', 'webpush'],
        'uniform_requests' => ['database', 'webpush'],
        'personnel_equipment_requests' => ['database', 'webpush'],
        'workgroup_evaluations' => ['database', 'webpush'],
        'training_assignments' => ['database', 'webpush'],
        'hub_support_tickets' => ['database', 'webpush'],
        'system_alerts' => ['database', 'webpush'],
        'project_alerts' => ['database'],
        'support_services_messages' => ['database', 'webpush', 'email'],
        'equipment_expirations' => ['database'],
        'todo_assignments' => ['database'],
        'captain5_ems' => ['database', 'webpush', 'email'],
        'captain5_assignment_requests' => ['database', 'webpush', 'email'],
        'command_communications' => ['database', 'webpush', 'email'],
    ];

    public function up(): void
    {
        $membersWithAChoice = $this->membersWithAChoice();

        foreach (self::PRESETS as $eventKey => $channels) {
            $turnOn = [];
            foreach ($channels as $channel) {
                $turnOn[$channel.'_enabled'] = true;
            }

            DB::table('user_notification_subscriptions')
                ->where('event_key', $eventKey)
                ->whereNotIn('user_id', $membersWithAChoice)
                ->where(function ($query) use ($turnOn): void {
                    foreach (array_keys($turnOn) as $column) {
                        $query->orWhere($column, false);
                    }
                })
                ->whereIn('user_id', fn ($users) => $users->select('id')->from('users')->where('account_status', 'active'))
                ->update($turnOn + ['updated_at' => now()]);
        }
    }

    /**
     * Intentionally a no-op: migrations are not reversed on a rollback here, and an image rollback leaves these
     * values in place. A member's own choice was never touched, and each member can change any channel in Settings.
     */
    public function down(): void {}

    /** @return list<int> */
    private function membersWithAChoice(): array
    {
        $rows = DB::table('user_notification_subscriptions')->get(['user_id', 'created_at', 'updated_at']);
        $rowsPerMinute = $rows->countBy(fn (object $row): string => $this->minute($row->created_at));

        return $rows
            ->filter(fn (object $row): bool => (string) $row->created_at !== (string) $row->updated_at
                || $rowsPerMinute[$this->minute($row->created_at)] < self::BULK_SEED_MINIMUM_ROWS)
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function minute(mixed $timestamp): string
    {
        return substr((string) $timestamp, 0, 16);
    }
};
