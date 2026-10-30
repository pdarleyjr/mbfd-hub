<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\AccountStatus;
use App\Filament\Pages\PushDeliveryHealth;
use App\Models\HubPushSubscription;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

final class PushDeliveryHealthPageTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    private const PUBLIC_KEY = 'BPUBLICKEYMARKERAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private const AUTH = 'AUTHMARKERAAAAAAAAAAAA';

    private const ENDPOINTS = [
        'apple' => 'https://web.push.apple.com/SECRET-APPLE-PATH',
        'fcm' => 'https://fcm.googleapis.com/fcm/send/SECRET-FCM-PATH',
        'mozilla' => 'https://updates.push.services.mozilla.com/wpush/v2/SECRET-MOZ-PATH',
        'wns' => 'https://wns2-ch1p.notify.windows.com/w/?token=SECRET-WNS-PATH',
        'never' => 'https://web.push.apple.com/SECRET-NEVER-PATH',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPermissionCache();
        Carbon::setTestNow('2026-10-30 12:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function device(User $user, string $endpoint, string $label, array $columns = []): HubPushSubscription
    {
        /** @var HubPushSubscription $device */
        $device = $user->pushSubscriptions()->create([
            'endpoint' => $endpoint, 'public_key' => self::PUBLIC_KEY, 'auth_token' => self::AUTH, 'device_label' => $label,
        ]);
        $device->forceFill($columns)->save();

        return $device;
    }

    private function superAdmin(): User
    {
        Role::findOrCreate('super_admin', 'web');

        return $this->withRole($this->member(), 'super_admin');
    }

    public function test_super_administrators_and_notification_administrators_may_open_the_page(): void
    {
        $this->actingAs($this->superAdmin());
        $this->assertTrue(PushDeliveryHealth::canAccess());

        $this->actingAs($this->withPermissions($this->member(), 'admin.access', 'admin.notifications.manage'));
        $this->assertTrue(PushDeliveryHealth::canAccess());
    }

    public function test_everyone_else_is_denied(): void
    {
        $this->actingAs($this->member());
        $this->assertFalse(PushDeliveryHealth::canAccess());

        // Viewing notifications settings is not administering them.
        $this->actingAs($this->withPermissions($this->member(), 'admin.access', 'admin.notifications.view'));
        $this->assertFalse(PushDeliveryHealth::canAccess());

        // The system-view permission is a different capability.
        $this->actingAs($this->withPermissions($this->member(), 'admin.access', 'admin.system.view'));
        $this->assertFalse(PushDeliveryHealth::canAccess());

        // A deactivated account loses it even with the permission.
        // (The test harness's actingAs() reactivates users, so the guard is set directly.)
        Auth::setUser($this->withPermissions($this->member(['account_status' => AccountStatus::Disabled]), 'admin.access', 'admin.notifications.manage'));
        $this->assertFalse(PushDeliveryHealth::canAccess());

        Livewire::actingAs($this->member())->test(PushDeliveryHealth::class)->assertForbidden();
    }

    public function test_an_unprivileged_member_cannot_load_the_page_over_http(): void
    {
        $this->actingAs($this->member())->get('/admin/push-delivery-health')->assertForbidden();
    }

    public function test_the_page_lists_each_device_with_its_exact_status_and_the_fixed_explanation(): void
    {
        $ana = $this->member(['name' => 'Ana Lopez']);
        $this->device($ana, self::ENDPOINTS['apple'], 'Safari on iPhone', [
            'last_success_at' => now()->subMinutes(5), 'content_encoding' => 'aes128gcm',
        ]);
        $this->device($ana, self::ENDPOINTS['fcm'], 'Chrome on Android', [
            'last_failure_at' => now()->subMinutes(2), 'last_failure_reason' => 'rejected (403)', 'failure_count' => 1,
        ]);
        $ben = $this->member(['name' => 'Ben Reyes']);
        $this->device($ben, self::ENDPOINTS['mozilla'], 'Firefox on Linux', [
            'last_failure_at' => now(), 'last_failure_reason' => 'rate_limited (429)', 'failure_count' => 1, 'backoff_until' => now()->addMinutes(3),
        ]);
        $this->device($ben, self::ENDPOINTS['wns'], 'Edge on Windows', ['last_failure_at' => now(), 'last_failure_reason' => 'rejected (410)']);
        $this->device($ben, self::ENDPOINTS['never'], 'Safari on iPad');

        $this->actingAs($this->superAdmin());

        $test = Livewire::test(PushDeliveryHealth::class)
            ->assertSuccessful()
            ->assertSee('Accepted means Apple/Google/Microsoft/Mozilla took the alert. Whether the phone displayed it is not observable by the server.')
            ->assertSee(['Ana Lopez', 'Ben Reyes', 'Safari on iPhone', 'Chrome on Android', 'Firefox on Linux', 'Edge on Windows', 'Safari on iPad'])
            ->assertSee(['Apple', 'Google', 'Mozilla', 'Microsoft'])
            ->assertSee(['Accepted by push service', 'Rejected by push service', 'Waiting to retry', 'Device setup needed', 'Never attempted'])
            ->assertSee('The push service refused the alert (code 403).')
            ->assertSee('aes128gcm');

        $html = $test->html();
        $this->assertStringNotContainsStringIgnoringCase('delivered', $html);
    }

    public function test_the_rendered_page_never_contains_an_endpoint_a_key_or_the_auth_secret(): void
    {
        $user = $this->member(['name' => 'Ana Lopez']);
        foreach (self::ENDPOINTS as $name => $endpoint) {
            $this->device($user, $endpoint, "Device {$name}", ['last_success_at' => now()]);
        }
        $this->actingAs($this->superAdmin());

        $html = Livewire::test(PushDeliveryHealth::class)->assertSee('Ana Lopez')->html();

        foreach (array_merge(array_values(self::ENDPOINTS), [
            'SECRET-APPLE-PATH', 'SECRET-FCM-PATH', 'SECRET-MOZ-PATH', 'SECRET-WNS-PATH', 'SECRET-NEVER-PATH',
            'web.push.apple.com', 'fcm.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com',
            self::PUBLIC_KEY, 'BPUBLICKEYMARKER', self::AUTH, 'AUTHMARKER',
        ]) as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "page must not contain [{$forbidden}]");
        }
    }

    public function test_the_page_loads_no_key_columns_at_all(): void
    {
        $user = $this->member();
        $this->device($user, self::ENDPOINTS['apple'], 'Safari on iPhone');
        $this->actingAs($this->superAdmin());

        $page = new PushDeliveryHealth;
        $query = (fn () => $this->devices())->call($page);

        $this->assertStringNotContainsString('public_key', $query->toSql());
        $this->assertStringNotContainsString('auth_token', $query->toSql());
        $this->assertArrayNotHasKey('public_key', $query->first()->getAttributes());
        $this->assertArrayNotHasKey('auth_token', $query->first()->getAttributes());
    }

    public function test_a_member_can_be_found_by_name(): void
    {
        $this->device($this->member(['name' => 'Ana Lopez']), self::ENDPOINTS['apple'], 'Safari on iPhone');
        $this->device($this->member(['name' => 'Ben Reyes']), self::ENDPOINTS['fcm'], 'Chrome on Android');
        $this->actingAs($this->superAdmin());

        Livewire::test(PushDeliveryHealth::class)
            ->searchTable('Ben')
            ->assertSee('Ben Reyes')
            ->assertDontSee('Ana Lopez');
    }

    public function test_the_page_is_registered_in_the_admin_panel_under_system(): void
    {
        $this->assertContains(PushDeliveryHealth::class, Filament::getPanel('admin')->getPages());
        $this->assertSame('System', PushDeliveryHealth::getNavigationGroup());
        $this->assertSame('/admin/push-delivery-health', parse_url(PushDeliveryHealth::getUrl(panel: 'admin'), PHP_URL_PATH));
    }
}
