<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationDecision;
use App\Services\Notifications\NotificationRecipientEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

/**
 * Owner-directed preset: the bell is on for every category, phone alerts are on wherever a category can
 * send them (except one-per-checkout), and the city email is on for important/critical categories plus
 * Support Services messages. Presets change which channels are PREFERRED, never who is eligible.
 */
final class NotificationPresetPolicyTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_30_210000_preset_notification_preferences.php';

    /** The policy, written out by hand: category key => [in-app, push, email]. */
    private const TABLE = [
        'member_request_updates' => [true, true, true],
        'department_updates' => [true, true, false],
        'vehicle_inspections' => [true, true, false],
        'daily_checkout_every_submission' => [true, false, false],
        'vehicle_swap_reports' => [true, true, true],
        'vehicle_identification_reports' => [true, true, true],
        'apparatus_service_tickets' => [true, true, false],
        'vehicle_maintenance_alerts' => [true, false, false],
        'station_inspections' => [true, true, false],
        'station_requests' => [true, true, false],
        'station_inventory_alerts' => [true, true, false],
        'uniform_requests' => [true, true, false],
        'personnel_equipment_requests' => [true, true, false],
        'workgroup_evaluations' => [true, true, false],
        'training_assignments' => [true, true, false],
        'hub_support_tickets' => [true, true, false],
        'system_alerts' => [true, true, false],
        'project_alerts' => [true, false, false],
        'support_services_messages' => [true, true, true],
        'equipment_expirations' => [true, false, false],
        'todo_assignments' => [true, false, false],
        'captain5_ems' => [true, true, true],
        'captain5_assignment_requests' => [true, true, true],
        'command_communications' => [true, true, true],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPermissionCache();
    }

    private function runMigration(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    private function save(User $user, string $key, bool $database, bool $push, bool $email): void
    {
        DB::table('user_notification_subscriptions')->insert([
            'user_id' => $user->id, 'event_key' => $key,
            'database_enabled' => $database, 'webpush_enabled' => $push, 'email_enabled' => $email,
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
        ]);
    }

    /** @return array{0: bool, 1: bool, 2: bool}|null */
    private function stored(User $user, string $key): ?array
    {
        $row = DB::table('user_notification_subscriptions')->where('user_id', $user->id)->where('event_key', $key)->first();

        return $row === null ? null : [(bool) $row->database_enabled, (bool) $row->webpush_enabled, (bool) $row->email_enabled];
    }

    public function test_every_category_default_matches_the_policy_table_for_the_channels_it_supports(): void
    {
        $registry = app(NotificationCategoryRegistry::class);

        $this->assertEqualsCanonicalizing(array_keys(self::TABLE), $registry->keys(), 'a new category needs a deliberate preset decision');
        foreach (self::TABLE as $key => [$database, $push, $email]) {
            $this->assertSame(
                ['database' => $database, 'webpush' => $push, 'email' => $email],
                $registry->get($key)->defaults,
                $key,
            );
        }
    }

    public function test_the_policy_never_defaults_on_a_channel_the_category_cannot_use(): void
    {
        foreach (app(NotificationCategoryRegistry::class)->all() as $category) {
            foreach (NotificationCategory::ALL_CHANNELS as $channel) {
                if (! $category->supports($channel)) {
                    $this->assertFalse($category->defaults[$channel], "{$category->key} cannot send {$channel}");
                }
            }
            $this->assertTrue($category->defaults['database'], "{$category->key}: the bell is on");
        }
    }

    public function test_phone_is_on_everywhere_it_is_supported_except_one_alert_per_checkout_and_email_follows_significance(): void
    {
        foreach (app(NotificationCategoryRegistry::class)->all() as $category) {
            if ($category->supports('webpush')) {
                $this->assertSame($category->key !== 'daily_checkout_every_submission', $category->defaults['webpush'], $category->key);
            }
            if ($category->supports('email')) {
                $expected = in_array($category->significance, ['important', 'critical'], true) || $category->key === 'support_services_messages';
                $this->assertSame($expected, $category->defaults['email'], $category->key);
            }
        }
    }

    public function test_an_eligible_member_with_no_saved_choice_gets_the_defaults_and_reachability_still_applies(): void
    {
        $evaluator = app(NotificationRecipientEvaluator::class);
        $reviewer = $this->withPermissions($this->rosterMember('Firefighter', 'reviewer@miamibeachfl.gov'), 'admin.access', 'admin.stations.view');

        $preferred = $evaluator->effectivePreference(app(NotificationCategoryRegistry::class)->get('station_requests'), null);
        $this->assertSame(['database' => true, 'webpush' => true, 'email' => false], $preferred);

        // No phone enrolled yet: the preference is on, but a server setting cannot enroll a device.
        $decision = $evaluator->decide($reviewer, 'station_requests');
        $this->assertSame(['database'], $decision->channels);
        $this->assertSame(NotificationDecision::NO_DEVICE, $decision->denials['webpush']);

        $this->addPushDevice($reviewer);
        $this->assertSame(['database', 'webpush'], $evaluator->decide($reviewer->fresh(), 'station_requests')->channels);
    }

    public function test_rows_created_later_use_the_preset_and_never_overwrite_a_choice(): void
    {
        $user = $this->member();
        $this->assertSame(['database_enabled' => true, 'webpush_enabled' => true, 'email_enabled' => true], UserNotificationSubscription::presetFor('member_request_updates'));
        $this->assertSame(['database_enabled' => true, 'webpush_enabled' => false, 'email_enabled' => false], UserNotificationSubscription::presetFor('daily_checkout_every_submission'));

        UserNotificationSubscription::ensureVehicleSwapForUser($user->id);
        $this->assertSame([true, true, true], $this->stored($user, 'vehicle_swap_reports'));

        $other = $this->member();
        $this->save($other, 'vehicle_swap_reports', true, false, false);
        UserNotificationSubscription::ensureVehicleSwapForUser($other->id);
        $this->assertSame([true, false, false], $this->stored($other, 'vehicle_swap_reports'), 'a saved choice is never overwritten');
    }

    public function test_the_migration_turns_on_exactly_the_policy_channels_in_saved_rows(): void
    {
        $user = $this->member();
        foreach (array_keys(self::TABLE) as $key) {
            $this->save($user, $key, false, false, false);
        }

        $this->runMigration();

        foreach (app(NotificationCategoryRegistry::class)->all() as $category) {
            $this->assertSame(
                [$category->defaults['database'], $category->defaults['webpush'], $category->defaults['email']],
                $this->stored($user, $category->key),
                $category->key.' now equals what a member with no saved choice gets',
            );
        }
    }

    public function test_the_migration_never_turns_anything_off_and_never_enables_an_unsupported_channel(): void
    {
        $user = $this->member();
        // Everything on, including the phone for the one category whose default is off.
        $this->save($user, 'daily_checkout_every_submission', true, true, true);
        $this->save($user, 'hub_support_tickets', true, true, true);
        // A member who chose only email stays that way for that channel; the bell and phone come on.
        $this->save($user, 'member_request_updates', false, false, true);
        // Categories that cannot send the phone or email keep those channels off.
        $this->save($user, 'vehicle_maintenance_alerts', false, false, false);
        $this->save($user, 'department_updates', false, false, false);
        // Members' own opt-out of email on a routine category is untouched.
        $this->save($user, 'station_requests', true, false, false);

        $this->runMigration();

        $this->assertSame([true, true, true], $this->stored($user, 'daily_checkout_every_submission'), 'nothing turned off');
        $this->assertSame([true, true, true], $this->stored($user, 'hub_support_tickets'));
        $this->assertSame([true, true, true], $this->stored($user, 'member_request_updates'));
        $this->assertSame([true, false, false], $this->stored($user, 'vehicle_maintenance_alerts'), 'in-app only category');
        $this->assertSame([true, true, false], $this->stored($user, 'department_updates'), 'no email for this category');
        $this->assertSame([true, true, false], $this->stored($user, 'station_requests'));
    }

    public function test_the_migration_creates_no_rows_skips_inactive_accounts_and_ignores_unknown_keys(): void
    {
        $withoutRow = $this->member();
        $inactive = $this->member(['account_status' => AccountStatus::Disabled]);
        $active = $this->member();
        $this->save($inactive, 'station_requests', false, false, false);
        $this->save($active, 'some_retired_category', false, false, false);
        $before = DB::table('user_notification_subscriptions')->count();

        $this->runMigration();

        $this->assertSame($before, DB::table('user_notification_subscriptions')->count(), 'defaults cover members without a row');
        $this->assertNull($this->stored($withoutRow, 'station_requests'));
        $this->assertSame([false, false, false], $this->stored($inactive, 'station_requests'));
        $this->assertSame([false, false, false], $this->stored($active, 'some_retired_category'));
    }

    public function test_the_migration_is_idempotent_and_down_changes_nothing(): void
    {
        $user = $this->member();
        $this->save($user, 'support_services_messages', false, false, false);

        $this->runMigration();
        $once = DB::table('user_notification_subscriptions')->get()->toArray();
        $this->runMigration();
        (require base_path(self::MIGRATION))->down();

        $this->assertEquals($once, DB::table('user_notification_subscriptions')->get()->toArray());
        $this->assertSame([true, true, true], $this->stored($user, 'support_services_messages'));
    }

    public function test_presets_never_change_who_is_eligible(): void
    {
        $evaluator = app(NotificationRecipientEvaluator::class);
        $registry = app(NotificationCategoryRegistry::class);
        $outsider = $this->member();
        // An ineligible member with every category saved fully on, before and after the migration.
        foreach (array_keys(self::TABLE) as $key) {
            $this->save($outsider, $key, true, true, true);
        }
        $this->addPushDevice($outsider);

        $this->runMigration();

        foreach (['station_requests', 'hub_support_tickets', 'uniform_requests', 'vehicle_inspections', 'captain5_ems', 'system_alerts', 'support_services_messages', 'daily_checkout_every_submission'] as $key) {
            $decision = $evaluator->decide($outsider->fresh(), $key);
            $this->assertFalse($decision->eligible, $key);
            $this->assertSame(NotificationDecision::NOT_ELIGIBLE, $decision->denial, $key);
            $this->assertSame([], $decision->channels, $key);
        }
        foreach ($registry->all() as $category) {
            if (in_array($category->key, ['member_request_updates', 'department_updates'], true)) {
                continue; // every active member is eligible for these two, by design
            }
            $this->assertFalse($evaluator->recipients($category->key)->contains('id', $outsider->id), $category->key.' recipients exclude an ineligible member');
        }
        $this->assertSame([], $registry->forUser($outsider)->keys()->diff(['member_request_updates', 'department_updates'])->all(), 'an ordinary member sees only the member categories');
    }

    public function test_an_inactive_account_still_receives_nothing_even_with_everything_on(): void
    {
        $evaluator = app(NotificationRecipientEvaluator::class);
        $disabled = $this->withPermissions($this->member(['account_status' => AccountStatus::Disabled]), 'admin.access', 'admin.stations.view');
        $this->save($disabled, 'station_requests', true, true, true);

        $this->runMigration();

        $decision = $evaluator->decide($disabled->fresh(), 'station_requests');
        $this->assertFalse($decision->eligible);
        $this->assertSame(NotificationDecision::ACCOUNT_INACTIVE, $decision->denial);
    }
}
