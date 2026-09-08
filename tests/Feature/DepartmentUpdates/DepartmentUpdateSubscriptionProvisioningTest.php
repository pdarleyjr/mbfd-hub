<?php

declare(strict_types=1);

namespace Tests\Feature\DepartmentUpdates;

use App\Enums\AccountStatus;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Jobs\SendDepartmentUpdateNotification;
use App\Models\DepartmentUpdate;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\DepartmentUpdateNotification;
use App\Services\DepartmentUpdates\DepartmentUpdateAudienceResolver;
use App\Services\Identity\CanonicalUserProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DepartmentUpdateSubscriptionProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_jit_canonical_user_gets_idempotent_defaults_and_receives_everyone_in_app_update(): void
    {
        Notification::fake();
        $employee = Employee::query()->create([
            'employee_id' => 'JIT-DU-001',
            'name' => 'JIT Department Update Member',
            'rank' => 'Firefighter',
            'password' => Hash::make('verified-legacy-password'),
            'must_change_password' => false,
        ]);

        $first = app(CanonicalUserProvisioner::class)->create(
            $employee->id,
            'LEGACY_HUMAN_BCRYPT_UNCHANGED',
            now(),
        );
        $repeat = app(CanonicalUserProvisioner::class)->create(
            $employee->id,
            'LEGACY_HUMAN_BCRYPT_UNCHANGED',
            now(),
        );

        self::assertTrue($first['created']);
        self::assertFalse($repeat['created']);
        self::assertTrue($first['user']->is($repeat['user']));
        $subscription = $first['user']->notificationSubscriptions()
            ->where('event_key', User::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES)
            ->sole();
        self::assertTrue($subscription->database_enabled);
        self::assertTrue($subscription->webpush_enabled);
        self::assertFalse($subscription->email_enabled);

        $update = DepartmentUpdate::query()->create([
            'title' => 'Everyone notice',
            'body' => '<p>Visible to every canonical member.</p>',
            'category' => 'general',
            'priority' => 'normal',
            'status' => 'published',
            'publish_at' => now()->subMinute(),
            'send_in_app' => true,
            'send_web_push' => false,
            'audience' => 'everyone',
            'author_id' => User::factory()->create()->id,
        ]);
        (new SendDepartmentUpdateNotification($update->id))->handle(
            app(DepartmentUpdateAudienceResolver::class),
        );

        Notification::assertSentTo($first['user'], DepartmentUpdateNotification::class);
        self::assertSame(1, $first['user']->notificationSubscriptions()
            ->where('event_key', User::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES)
            ->count());
    }

    public function test_admin_created_user_gets_department_update_defaults(): void
    {
        $superAdmin = User::factory()->create(['account_status' => AccountStatus::Active, 'password' => 'Current-admin-password-2026!']);
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($superAdmin);
        $employee = Employee::query()->create([
            'name' => 'Admin Created Member',
            'employee_id' => 'ADMIN-DU-001',
            'rank' => 'Firefighter',
            'roster_status' => 'active',
            'password' => Hash::make('Existing-roster-credential'),
        ]);

        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->callAction('createLoginAccount', data: [
                'temporary_password' => 'temporary-password-123',
                'current_password' => 'Current-admin-password-2026!',
                'reason' => 'Create the approved employee login account',
            ])->assertHasNoActionErrors();

        $user = User::query()->where('employee_profile_id', $employee->id)->sole();
        self::assertSame($employee->employee_id, $user->employee_id);
        self::assertSame(AccountStatus::Active, $user->account_status);
        self::assertTrue($user->must_change_password);
        $subscription = $user->notificationSubscriptions()
            ->where('event_key', User::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES)
            ->sole();
        self::assertTrue($subscription->database_enabled);
        self::assertTrue($subscription->webpush_enabled);
        self::assertFalse($subscription->email_enabled);
    }
}
