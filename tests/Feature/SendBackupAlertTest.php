<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\CriticalAlertNotification;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SendBackupAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake();
    }

    private function withPushDevice(User $user): User
    {
        $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/'.$user->id, str_repeat('a', 88), str_repeat('b', 24));

        return $user;
    }

    public function test_safe_alert_reaches_only_active_administrators_in_both_existing_channels(): void
    {
        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = $this->withPushDevice(User::factory()->create(['account_status' => 'active']));
        $admin->assignRole('admin');
        $pending = $this->withPushDevice(User::factory()->create());
        $pending->assignRole('admin');
        $member = $this->withPushDevice(User::factory()->create(['account_status' => 'active']));

        $this->artisan('mbfd:backup-alert', ['kind' => 'backup_failure', '--test' => true])
            ->assertExitCode(0);

        Notification::assertSentTo($admin, DatabaseNotification::class);
        Notification::assertSentTo($admin, CriticalAlertNotification::class, function (CriticalAlertNotification $alert) use ($admin): bool {
            return $alert->toArray($admin)['message'] === 'This is a safe backup alert delivery test. No backup operation was changed.';
        });
        Notification::assertNotSentTo($pending, CriticalAlertNotification::class);
        Notification::assertNotSentTo($member, CriticalAlertNotification::class);
        Notification::assertNotSentTo($member, DatabaseNotification::class);
    }

    public function test_repeated_failure_alert_is_deduplicated(): void
    {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $admin = $this->withPushDevice(User::factory()->create(['account_status' => 'active']));
        $admin->assignRole('super_admin');

        $this->artisan('mbfd:backup-alert', ['kind' => 'repository_inaccessible'])->assertExitCode(0);
        $this->artisan('mbfd:backup-alert', ['kind' => 'repository_inaccessible'])->assertExitCode(0);

        Notification::assertSentToTimes($admin, DatabaseNotification::class, 1);
        Notification::assertSentToTimes($admin, CriticalAlertNotification::class, 1);
    }

    public function test_an_administrator_without_a_push_device_still_gets_the_in_app_alert(): void
    {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['account_status' => 'active']);
        $admin->assignRole('super_admin');

        $this->artisan('mbfd:backup-alert', ['kind' => 'backup_stale'])->assertExitCode(0);

        Notification::assertSentTo($admin, DatabaseNotification::class);
        Notification::assertNotSentTo($admin, CriticalAlertNotification::class);
    }

    public function test_unknown_category_is_rejected_without_delivery(): void
    {
        $this->artisan('mbfd:backup-alert', ['kind' => 'other'])->assertExitCode(1);

        Notification::assertNothingSent();
    }
}
