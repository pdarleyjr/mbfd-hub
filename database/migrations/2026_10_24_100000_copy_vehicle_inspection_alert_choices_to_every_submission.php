<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restores the old per-checkout alert as an opt-in category.
 *
 * Before the notification rework, a person who turned on "Vehicle Inspections: receive alerts when a
 * vehicle inspection is submitted" (event_key `vehicle_inspections`) was told about every Daily Checkout.
 * That alert is now its own category, `daily_checkout_every_submission`, that nobody has by default. So the
 * people who explicitly chose the old alert keep what they chose, each row's channel flags are copied to a
 * new row for the same person, and only where at least one channel was on.
 *
 * Additive and idempotent: an existing row for the new category is never touched (the unique index on
 * user and event key makes INSERT OR IGNORE skip it), so running this twice, or after someone already saved
 * a choice, changes nothing. Whether a person actually receives the alert is still decided at send time by
 * their current role (reviewers only), never by this row.
 */
return new class extends Migration
{
    private const OLD_KEY = 'vehicle_inspections';

    private const NEW_KEY = 'daily_checkout_every_submission';

    public function up(): void
    {
        $now = now();

        DB::table('user_notification_subscriptions')
            ->where('event_key', self::OLD_KEY)
            ->where(fn ($flags) => $flags
                ->where('database_enabled', true)
                ->orWhere('webpush_enabled', true)
                ->orWhere('email_enabled', true))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($now): void {
                foreach ($rows as $row) {
                    DB::table('user_notification_subscriptions')->insertOrIgnore([
                        'user_id' => $row->user_id,
                        'event_key' => self::NEW_KEY,
                        'database_enabled' => (bool) $row->database_enabled,
                        'webpush_enabled' => (bool) $row->webpush_enabled,
                        'email_enabled' => (bool) $row->email_enabled,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    /**
     * Intentionally a no-op. After this ran, a copied row is indistinguishable from a choice the person
     * made themselves on the settings page, and deleting it on rollback could erase that choice. Without the
     * category's code the rows are inert (nothing reads them), and the original `vehicle_inspections` rows
     * were never changed.
     */
    public function down(): void {}
};
