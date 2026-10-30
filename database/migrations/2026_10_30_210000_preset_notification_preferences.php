<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data only (no schema change). Owner-directed preset: every active member's SAVED notification choices are
 * brought up to the same defaults a member with no saved choice now gets (see NotificationPresetPolicy):
 * the bell on for every category, phone alerts on wherever the category can send them (except "Every Daily
 * Checkout as it's submitted"), and the city email on for important and critical categories plus Support
 * Services messages.
 *
 * - Only turns channels ON. Nothing is ever turned off.
 * - Only touches channels the category supports (the list below is the policy frozen on the day it ran).
 * - Creates no rows: members without a saved choice already get these defaults.
 * - Skips accounts that are not active. Who actually receives an alert is still decided at send time
 *   (eligibility, an enrolled phone, a verified city email), so this changes none of that.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> category key => channels to turn on */
    private const PRESETS = [
        'member_request_updates' => ['database', 'webpush', 'email'],
        'department_updates' => ['database', 'webpush'],
        'vehicle_inspections' => ['database', 'webpush'],
        'daily_checkout_every_submission' => ['database'],
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
        foreach (self::PRESETS as $eventKey => $channels) {
            $turnOn = [];
            foreach ($channels as $channel) {
                $turnOn[$channel.'_enabled'] = true;
            }

            DB::table('user_notification_subscriptions')
                ->where('event_key', $eventKey)
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
     * Intentionally a no-op: members' earlier opt-outs cannot be told apart from the preset afterward,
     * so this owner-directed reset cannot be undone row by row.
     */
    public function down(): void {}
};
