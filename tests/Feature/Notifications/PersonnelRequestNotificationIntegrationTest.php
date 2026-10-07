<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\PersonnelRequestStatus;
use App\Enums\PersonnelRequestType;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\NewSubmissionNotification;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\CanonicalNotificationInbox;
use App\Services\Identity\CanonicalSessionIssuer;
use App\Services\PersonnelRequests\PersonnelRequestNotifier;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PersonnelRequestNotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_employee_workflow_alerts_use_the_exact_canonical_inbox_and_keep_legacy_fallback(): void
    {
        $employee = $this->employee('MEMBER-INBOX');
        $user = $this->account($employee);
        $other = $this->account($this->employee('OTHER-INBOX'));
        Notification::make()->title('Existing workflow update')->sendToDatabase($employee);

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());
        $this->assertSame(0, $employee->notifications()->count());
        $this->assertSame('Existing workflow update', $user->notifications()->sole()->data['title']);

        $legacy = $this->employee('LEGACY-INBOX');
        Notification::make()->title('Historical member update')->sendToDatabase($legacy);
        $this->assertSame(1, $legacy->notifications()->count());
    }

    public function test_historical_inbox_bridge_preserves_rows_and_skips_unmapped_or_mismatched_people(): void
    {
        $employee = $this->employee('HISTORY-MEMBER');
        Notification::make()->title('Preserved history')->sendToDatabase($employee);
        $notification = $employee->notifications()->sole();
        $notification->markAsRead();
        $before = $notification->refresh()->getRawOriginal();
        $user = $this->account($employee);
        $unmapped = $this->employee('UNMAPPED-HISTORY');
        Notification::make()->title('Unmapped history')->sendToDatabase($unmapped);
        $mismatch = $this->employee('MISMATCH-HISTORY');
        Notification::make()->title('Unchanged mismatched history')->sendToDatabase($mismatch);
        User::factory()->create(['employee_profile_id' => $mismatch->id, 'employee_id' => 'DIFFERENT-PERSON']);

        $migration = require database_path('migrations/2026_10_07_160100_register_personnel_request_notification_channels.php');
        $migration->up();
        $after = (array) DB::table('notifications')->where('id', $notification->id)->first();
        $this->assertSame($user->id, (int) $after['notifiable_id']);
        $this->assertSame(User::class, $after['notifiable_type']);
        foreach (['id', 'type', 'data', 'read_at', 'created_at', 'updated_at'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $this->assertSame(1, $unmapped->notifications()->count());
        $this->assertSame(1, $mismatch->notifications()->count());
        $migration->up();
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_item_arrivals_and_messages_reach_only_the_member_and_use_item_specific_actions(): void
    {
        $employee = $this->employee('ITEM-ALERT');
        $user = $this->account($employee);
        $other = $this->account($this->employee('OTHER-ITEM-ALERT'));
        $request = $this->request($employee);
        $item = $request->items()->create(['item_code' => 'polo_shirt_ss', 'item_name' => 'Short Sleeve Polo', 'category' => 'uniform', 'quantity' => 3]);
        UserNotificationSubscription::ensurePersonnelRequestsForUser($user);
        $user->notificationSubscriptions()->where('event_key', User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES)->update(['database_enabled' => false]);

        app(PersonnelRequestNotifier::class)->memberUpdated($request, 'item_arrived', 'One of three polos is ready.', $item);
        $notice = $user->notifications()->sole();
        $this->assertSame('Short Sleeve Polo: Arrived', $notice->data['title']);
        $this->assertStringContainsString('One of three polos is ready.', $notice->data['body']);
        $this->assertSame('/employee/my-requests/'.$request->public_id.'#item-'.$item->id, data_get($notice->data, 'actions.0.url'));
        $this->assertSame(0, $other->notifications()->count());
        $this->assertSame(0, $employee->notifications()->count());
        app(PersonnelRequestNotifier::class)->memberUpdated($request, message: 'Please collect your items tomorrow.');
        $this->assertSame(2, $user->notifications()->count());
    }

    public function test_uniform_admin_option_preserves_default_delivery_and_respects_explicit_channel_opt_out(): void
    {
        Role::findOrCreate('logistics_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('logistics_admin');
        $other = User::factory()->create();
        $request = $this->request($this->employee('ADMIN-OPTION'));
        $notifier = app(PersonnelRequestNotifier::class);
        $notifier->created($request);
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());
        $subscription = $admin->notificationSubscriptions()->where('event_key', User::NOTIFICATION_PREFERENCE_UNIFORM_REQUESTS)->sole();
        $this->assertTrue($subscription->database_enabled);
        $this->assertFalse($subscription->webpush_enabled);
        $this->assertFalse($subscription->email_enabled);
        $subscription->update(['database_enabled' => false]);
        $notifier->adminMessageReceived($request, 'My sizing has changed.');
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertFalse($subscription->refresh()->database_enabled);
    }

    public function test_member_essential_inbox_keeps_external_channels_opt_in_and_requires_a_live_device_subscription(): void
    {
        $employee = $this->employee('EXTERNAL-CHANNELS');
        $employee->update(['city_email' => 'member@miamibeachfl.gov']);
        $user = $this->account($employee);
        $notice = new NewSubmissionNotification('member_request_update', 'Order update', 'Items arrived.', essentialInApp: true);
        $this->assertSame(['database'], $notice->via($user));
        UserNotificationSubscription::ensurePersonnelRequestsForUser($user);
        $subscription = $user->notificationSubscriptions()->where('event_key', User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES)->sole();
        $subscription->update(['database_enabled' => false, 'webpush_enabled' => true, 'email_enabled' => true]);
        $this->assertSame(['database', BudgetedMailChannel::class], $notice->via($user));
        $user->updatePushSubscription('https://push.example.test/member', str_repeat('a', 88), str_repeat('b', 24));
        $this->assertSame(['database', WebPushChannel::class, BudgetedMailChannel::class], $notice->via($user));
        $user->pushSubscriptions()->delete();
        $this->assertSame(['database', BudgetedMailChannel::class], $notice->via($user));
        $subscription->update(['email_enabled' => false]);
        $this->assertSame(['database'], $notice->via($user));
    }

    public function test_alert_created_before_a_future_approved_link_is_visible_after_canonical_session_login(): void
    {
        $employee = $this->employee('FUTURE-LINK');
        Notification::make()->title('Arrived before onboarding')->sendToDatabase($employee);
        $notice = $employee->notifications()->sole();
        $notice->markAsRead();
        $original = $notice->refresh()->getRawOriginal();
        $user = User::factory()->create(['account_status' => 'active', 'employee_id' => null, 'employee_profile_id' => null]);
        $migration = require database_path('migrations/2026_10_07_160100_register_personnel_request_notification_channels.php');
        $migration->up();
        $this->assertSame(1, $employee->notifications()->count());
        $user = app(AccountSecurityService::class)->completeCanonicalLink($user, $employee->id, $employee->employee_id, null, now())['user'];
        $this->assertSame(0, $user->notifications()->count());

        $sessionId = app(CanonicalSessionIssuer::class)->issue($this->sessionRequest(), $user);
        $this->assertNotSame('', $sessionId);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(0, $employee->notifications()->count());
        $moved = $user->notifications()->sole()->getRawOriginal();
        foreach (['id', 'type', 'data', 'read_at', 'created_at', 'updated_at'] as $field) {
            $this->assertSame($original[$field], $moved[$field]);
        }
        app(CanonicalSessionIssuer::class)->issue($this->sessionRequest(), $user->fresh());
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_future_inbox_hook_skips_unbound_mismatched_and_stale_identity_links(): void
    {
        $employee = $this->employee('UNLINKED-ALERT');
        Notification::make()->title('Unlinked alert')->sendToDatabase($employee);
        $unbound = User::factory()->create(['account_status' => 'active']);
        app(CanonicalSessionIssuer::class)->issue($this->sessionRequest(), $unbound);
        $this->assertSame(0, $unbound->notifications()->count());
        $mismatch = User::factory()->create(['account_status' => 'active', 'employee_profile_id' => $employee->id, 'employee_id' => 'NOT-THE-EMPLOYEE']);
        $this->assertSame(0, app(CanonicalNotificationInbox::class)->migrateEmployeeAlerts($mismatch));
        try {
            app(CanonicalSessionIssuer::class)->issue($this->sessionRequest(), $mismatch);
            $this->fail('A mismatched identity received a canonical session.');
        } catch (\LogicException $exception) {
            $this->assertSame('A canonical session cannot be issued for this account.', $exception->getMessage());
        }
        $stale = $this->account($this->employee('STALE-INBOX'));
        $newProfile = $this->employee('CHANGED-INBOX');
        DB::table('users')->where('id', $stale->id)->update(['employee_profile_id' => $newProfile->id, 'employee_id' => $newProfile->employee_id]);
        $this->assertSame(0, app(CanonicalNotificationInbox::class)->migrateEmployeeAlerts($stale));
        $this->assertSame(1, $employee->notifications()->count());
        $this->assertSame(0, $mismatch->notifications()->count());
    }

    public function test_admin_promotion_gets_submission_defaults_while_a_genuine_existing_opt_out_stays_disabled(): void
    {
        $employee = $this->employee('PROMOTED-ADMIN');
        $promoted = $this->account($employee);
        $migration = require database_path('migrations/2026_10_07_160100_register_personnel_request_notification_channels.php');
        $migration->up();
        $this->assertSame(1, $promoted->notificationSubscriptions()->count());
        $this->assertSame(User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES, $promoted->notificationSubscriptions()->sole()->event_key);
        Role::findOrCreate('logistics_admin', 'web');
        $promoted->assignRole('logistics_admin');
        $request = $this->request($employee);
        app(PersonnelRequestNotifier::class)->created($request);
        $this->assertSame(1, $promoted->notifications()->count());
        foreach ([User::NOTIFICATION_PREFERENCE_UNIFORM_REQUESTS, User::NOTIFICATION_PREFERENCE_PERSONNEL_EQUIPMENT_REQUESTS] as $key) {
            $this->assertTrue($promoted->notificationSubscriptions()->where('event_key', $key)->sole()->database_enabled);
        }

        $optedOut = User::factory()->create(['account_status' => 'active']);
        $optedOut->notificationSubscriptions()->create([
            'event_key' => User::NOTIFICATION_PREFERENCE_UNIFORM_REQUESTS, 'database_enabled' => false, 'webpush_enabled' => false, 'email_enabled' => false,
        ]);
        UserNotificationSubscription::ensurePersonnelRequestsForUser($optedOut);
        $optedOut->assignRole('logistics_admin');
        app(PersonnelRequestNotifier::class)->created($request);
        $this->assertSame(0, $optedOut->notifications()->count());
        $this->assertFalse($optedOut->notificationSubscriptions()->where('event_key', User::NOTIFICATION_PREFERENCE_UNIFORM_REQUESTS)->sole()->database_enabled);
        $this->assertTrue($optedOut->notificationSubscriptions()->where('event_key', User::NOTIFICATION_PREFERENCE_PERSONNEL_EQUIPMENT_REQUESTS)->sole()->database_enabled);
    }

    private function sessionRequest(): Request
    {
        $request = Request::create('/login', 'POST');
        $session = $this->app['session']->driver();
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }

    private function employee(string $number): Employee
    {
        return Employee::query()->create(['employee_id' => $number, 'name' => 'Notification Test Member', 'rank' => 'Firefighter', 'password' => 'test-only-credential', 'must_change_password' => false]);
    }

    private function account(Employee $employee): User
    {
        return User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id, 'account_status' => 'active']);
    }

    private function request(Employee $employee): PersonnelRequest
    {
        return PersonnelRequest::query()->create([
            'public_id' => (string) Str::ulid(), 'request_number' => 'NOTIFY-'.Str::random(8), 'type' => PersonnelRequestType::Uniform,
            'status' => PersonnelRequestStatus::Pending, 'beneficiary_employee_id' => $employee->id, 'requester_employee_id' => $employee->id,
            'beneficiary_name' => $employee->name, 'beneficiary_employee_number' => $employee->employee_id,
            'requester_name' => $employee->name, 'requester_employee_number' => $employee->employee_id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
