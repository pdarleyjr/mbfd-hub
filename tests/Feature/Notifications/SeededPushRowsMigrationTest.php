<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Seeded rows nobody chose must not leave a member, now or after they activate, with phone alerts off. */
final class SeededPushRowsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_01_130100_turn_on_push_in_seeded_notification_rows.php';

    private const SEEDED_AT = '2026-10-07 20:27:08';

    public function test_seeded_rows_get_push_on_for_active_and_not_yet_activated_members_and_nothing_else_moves(): void
    {
        $active = $this->account('active');
        $pending = $this->account('pending_activation');
        $this->seedRows([$active, $pending, $this->account('active'), $this->account('active'), $this->account('active')]);

        $this->migrateRows();

        foreach ([$active, $pending] as $user) {
            $row = $this->row($user, 'member_request_updates');
            $this->assertTrue((bool) $row->webpush_enabled);
            $this->assertFalse((bool) $row->email_enabled, 'only push moves');
            $this->assertSame(self::SEEDED_AT, (string) $row->updated_at, 'it still reads as never chosen');
        }
    }

    public function test_a_members_own_choice_is_never_changed(): void
    {
        $chose = $this->account('active');
        $this->seedRows([$chose, $this->account('active'), $this->account('active'), $this->account('active'), $this->account('active')]);
        // Turned push off in Settings: an edited row marks all of a member's rows as theirs.
        DB::table('user_notification_subscriptions')->where('user_id', $chose->id)->update(['updated_at' => '2026-10-20 10:00:00']);

        $this->migrateRows();

        $this->assertFalse((bool) $this->row($chose, 'member_request_updates')->webpush_enabled);
    }

    public function test_a_row_created_alone_counts_as_a_choice(): void
    {
        $chose = $this->account('active');
        $this->seedRows([$chose, $this->account('active'), $this->account('active'), $this->account('active'), $this->account('active')]);
        // The settings page only creates a row that differs from the default, one member at a time.
        DB::table('user_notification_subscriptions')->insert($this->values($chose, 'department_updates', '2026-10-20 10:00:00'));

        $this->migrateRows();

        $this->assertFalse((bool) $this->row($chose, 'member_request_updates')->webpush_enabled);
        $this->assertFalse((bool) $this->row($chose, 'department_updates')->webpush_enabled);
    }

    public function test_disabled_accounts_and_categories_that_stay_off_by_policy_are_left_alone(): void
    {
        $disabled = $this->account('disabled');
        $member = $this->account('active');
        $this->seedRows([$disabled, $member, $this->account('active'), $this->account('active'), $this->account('active')]);
        DB::table('user_notification_subscriptions')->insert([
            $this->values($member, 'daily_checkout_every_submission', self::SEEDED_AT),
            $this->values($member, 'fleet_operational_alerts', self::SEEDED_AT),
        ]);

        $this->migrateRows();

        $this->assertFalse((bool) $this->row($disabled, 'member_request_updates')->webpush_enabled);
        $this->assertFalse((bool) $this->row($member, 'daily_checkout_every_submission')->webpush_enabled);
        $this->assertFalse((bool) $this->row($member, 'fleet_operational_alerts')->webpush_enabled);
    }

    private function migrateRows(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    private function account(string $status): User
    {
        return User::factory()->create(['account_status' => $status]);
    }

    /** @param list<User> $users Enough rows in one minute to read as a bulk seed */
    private function seedRows(array $users): void
    {
        foreach ($users as $user) {
            DB::table('user_notification_subscriptions')->insert($this->values($user, 'member_request_updates', self::SEEDED_AT));
        }
    }

    /** @return array<string, mixed> */
    private function values(User $user, string $key, string $at): array
    {
        return ['user_id' => $user->id, 'event_key' => $key, 'database_enabled' => true, 'webpush_enabled' => false, 'email_enabled' => false, 'created_at' => $at, 'updated_at' => $at];
    }

    private function row(User $user, string $key): object
    {
        return DB::table('user_notification_subscriptions')->where('user_id', $user->id)->where('event_key', $key)->first();
    }
}
