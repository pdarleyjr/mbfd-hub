<?php

declare(strict_types=1);

namespace Tests\Feature\CommandBoard;

use App\Enums\AccountStatus;
use App\Filament\Admin\Pages\CommandBoardPin;
use App\Models\SecurityActionEvent;
use App\Models\User;
use App\Services\CommandBoard\CommandBoardPinAdministration;
use App\Services\Security\ApplicationAccessService;
use App\Support\ApplicationAccessRegistry;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class CommandBoardPinPageTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://cmd.example.test';

    private const PIN_ENDPOINT = self::BASE.'/api/internal/command-pin';

    private const TOKEN = 'cmd-service-token-for-tests-only';

    private const PASSWORD = 'Command-board-test-password!';

    private const NEW_PIN = '482913';

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config()->set('services.command_board.url', self::BASE);
        config()->set('services.command_board.service_token', self::TOKEN);
        config()->set('video-conferencing.command_pin_hash', null);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_migration_provisions_the_capability_without_granting_it_or_creating_roles(): void
    {
        $permission = Permission::query()->where('name', 'app.cmd.admin')->where('guard_name', 'web')->sole();
        self::assertSame(0, DB::table('model_has_permissions')->where('permission_id', $permission->id)->count());
        self::assertSame(0, DB::table('role_has_permissions')->where('permission_id', $permission->id)->count());
        self::assertSame(0, Role::query()->count());

        $migration = require database_path('migrations/2026_10_19_140000_add_command_board_pin_admin_permission.php');
        $migration->up();
        $migration->up();

        self::assertSame(1, Permission::query()->where('name', 'app.cmd.admin')->count());
        self::assertSame(0, Role::query()->count());
    }

    public function test_super_admin_can_grant_the_capability_from_the_employee_access_controls(): void
    {
        self::assertSame(
            'Command Board — legacy PIN rollback control (requires Hub administration access)',
            app(ApplicationAccessRegistry::class)->capabilityOptions()['app.cmd.admin'],
        );
        $actor = $this->superAdmin();
        $target = $this->member(['admin.access']);

        app(ApplicationAccessService::class)->syncAdministrationCapabilities($actor, $target, ['app.cmd.admin'], self::PASSWORD, 'Command Board PIN steward');

        self::assertTrue($target->fresh()->hasDirectWebPermission('app.cmd.admin'));
        $this->assertDatabaseHas('security_action_events', ['target_user_id' => $target->id, 'action' => 'change_admin_capabilities', 'result' => 'allowed']);
    }

    public function test_super_admin_can_open_the_page(): void
    {
        $this->fakeBoard();
        $this->actingAs($this->superAdmin());

        $this->get('/admin/command-board-pin')->assertOk()->assertSee('Command Board PIN')->assertSee('Custom PIN set in the Hub');
    }

    public function test_hub_administrator_with_the_direct_capability_can_open_the_page(): void
    {
        $this->fakeBoard();
        $this->actingAs($this->member(['admin.access', 'app.cmd.admin']));

        $this->get('/admin/command-board-pin')->assertOk()->assertSee('Custom PIN set in the Hub');
    }

    public function test_role_inherited_capability_does_not_open_the_page(): void
    {
        $this->fakeBoard();
        $role = Role::findOrCreate('cmd-pin-role-test', 'web');
        $role->givePermissionTo(Permission::findOrCreate('app.cmd.admin', 'web'));
        $admin = $this->member(['admin.access']);
        $admin->assignRole($role);
        $this->actingAs($admin);

        $this->get('/admin/command-board-pin')->assertForbidden();
        self::assertFalse(CommandBoardPin::canAccess());
        Http::assertNothingSent();
    }

    public function test_admin_whose_permissions_all_come_from_a_role_cannot_open_the_page(): void
    {
        $role = Role::findOrCreate('admin', 'web');
        $role->givePermissionTo([Permission::findOrCreate('admin.access', 'web'), Permission::findOrCreate('app.cmd.admin', 'web')]);
        $admin = $this->member();
        $admin->assignRole($role);
        $this->actingAs($admin);

        self::assertFalse(CommandBoardPin::canAccess());
        self::assertNotSame(200, $this->get('/admin/command-board-pin')->getStatusCode());
    }

    public function test_plain_employee_and_capability_without_hub_administration_are_forbidden(): void
    {
        foreach ([[], ['app.cmd.admin'], ['admin.access', 'app.cmd.access']] as $permissions) {
            $this->actingAs($this->member($permissions));

            self::assertFalse(CommandBoardPin::canAccess(), implode(',', $permissions));
            self::assertNotSame(200, $this->get('/admin/command-board-pin')->getStatusCode());
        }
        Http::assertNothingSent();
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function statuses(): array
    {
        return [
            'custom Hub PIN' => [
                ['configured' => true, 'source' => 'hub_admin', 'pinVersion' => 3, 'updatedAt' => '2026-10-19T14:05:00Z', 'updatedBy' => 'Capt. Pat Example'],
                ['Yes', 'Custom PIN set in the Hub', 'Oct 19, 2026 10:05 AM EDT', 'Capt. Pat Example'],
            ],
            'same as the 300 camera PIN' => [
                ['configured' => true, 'source' => 'video_conferencing_default', 'pinVersion' => 0, 'updatedAt' => null, 'updatedBy' => null],
                ['Yes', 'Same as the 300 camera PIN', 'Not recorded'],
            ],
            'not set' => [
                ['configured' => false, 'source' => 'none', 'pinVersion' => 0, 'updatedAt' => null, 'updatedBy' => null],
                ['No', 'Not set', 'Not recorded'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $expected
     */
    #[DataProvider('statuses')]
    public function test_status_panel_renders_each_source(array $payload, array $expected): void
    {
        $this->fakeBoard($payload);
        $this->actingAs($this->superAdmin());

        $component = Livewire::test(CommandBoardPin::class)->assertSet('linkConfigured', true)->assertSet('statusError', null);
        foreach ($expected as $text) {
            $component->assertSee($text);
        }
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::PIN_ENDPOINT
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_missing_service_token_says_the_link_is_not_configured_and_disables_actions(): void
    {
        config()->set('services.command_board.service_token', null);
        config()->set('video-conferencing.command_pin_hash', $this->cameraHash());
        Http::fake();
        $this->actingAs($this->superAdmin());

        Livewire::test(CommandBoardPin::class)
            ->assertSet('linkConfigured', false)
            ->assertSee('The Command Board link isn\'t configured')
            ->assertActionDisabled('setPin')
            ->assertActionDisabled('useCameraPin')
            ->callAction('setPin', data: $this->setPinData());

        Http::assertNothingSent();
        self::assertSame(0, SecurityActionEvent::query()->count());
    }

    public function test_status_read_failure_is_shown_without_leaking_the_token(): void
    {
        Http::fake([self::PIN_ENDPOINT => Http::response(['error' => 'unauthorized'], 401)]);
        $this->actingAs($this->superAdmin());

        Livewire::test(CommandBoardPin::class)
            ->assertSet('status', null)
            ->assertSee('The Command Board did not accept the Hub\'s service token')
            ->assertDontSee(self::TOKEN)
            ->assertActionEnabled('setPin');
    }

    public function test_redirects_from_the_board_are_reported_and_never_followed(): void
    {
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), self::BASE)) {
                return Http::response('', $request->method() === 'PUT' ? 307 : 302, ['Location' => 'https://elsewhere.example.test/collect']);
            }

            return Http::response(['configured' => true, 'source' => 'hub_admin', 'pinVersion' => 9, 'updatedAt' => null, 'updatedBy' => null]);
        });
        $this->actingAs($this->superAdmin());

        $component = Livewire::test(CommandBoardPin::class)
            ->assertSet('status', null)
            ->assertSee('answered with a redirect (HTTP 302)')
            ->callAction('setPin', data: $this->setPinData());

        $this->assertNotificationBody('answered with a redirect (HTTP 307)');
        $component->assertNotified('Command Board PIN not changed');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'elsewhere.example.test'));
        self::assertSame('redirected', SecurityActionEvent::query()->where('action', CommandBoardPinAdministration::AUDIT_ACTION)->sole()->metadata['error']);
    }

    public function test_audit_failure_after_the_board_accepted_the_pin_still_reports_the_change(): void
    {
        $this->fakeBoard();
        Log::spy();
        SecurityActionEvent::creating(function (SecurityActionEvent $event): void {
            if ($event->result === 'allowed') {
                throw new \RuntimeException('audit store unavailable');
            }
        });
        $this->actingAs($this->superAdmin());

        $component = Livewire::test(CommandBoardPin::class)
            ->callAction('setPin', data: $this->setPinData())
            ->assertHasNoActionErrors()
            ->assertSet('status.pin_version', 4);

        $this->assertNotificationBody('could not write its audit record');
        $component->assertNotified('Command Board PIN updated');
        self::assertTrue(Hash::check(self::NEW_PIN, (string) $this->sentPut()['pinHash']));
        self::assertSame(0, SecurityActionEvent::query()->count());
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context = []): bool {
            $encoded = (string) json_encode($context);

            return $message === 'command_board.pin.audit_failed'
                && ($context['result'] ?? null) === 'allowed'
                && ! str_contains($encoded, self::NEW_PIN)
                && ! str_contains($encoded, '$2y$');
        });
    }

    public function test_setting_a_pin_sends_only_a_bcrypt_hash_and_audits_without_the_pin(): void
    {
        $this->fakeBoard(put: ['configured' => true, 'source' => 'hub_admin', 'pinVersion' => 4, 'updatedAt' => '2026-10-19T15:00:00Z', 'updatedBy' => 'Capt. Pat Example']);
        $actor = $this->superAdmin();
        $this->actingAs($actor);

        Livewire::test(CommandBoardPin::class)
            ->callAction('setPin', data: $this->setPinData())
            ->assertHasNoActionErrors()
            ->assertNotified('Command Board PIN updated')
            ->assertSee('Oct 19, 2026 11:00 AM EDT');

        $put = $this->sentPut();
        $hash = $put['pinHash'];
        self::assertIsString($hash);
        self::assertMatchesRegularExpression('/^\$2y\$1[0-4]\$[.\/A-Za-z0-9]{53}$/', $hash);
        self::assertTrue(Hash::check(self::NEW_PIN, $hash));
        self::assertSame(['sub' => 'hub-user:'.$actor->id, 'name' => 'Capt. Pat Example'], $put['updatedBy']);
        self::assertSame(['pinHash', 'updatedBy'], array_keys($put));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && ! str_contains($request->body(), self::NEW_PIN));

        $event = SecurityActionEvent::query()->where('action', CommandBoardPinAdministration::AUDIT_ACTION)->sole();
        self::assertSame('allowed', $event->result);
        self::assertSame('Rotating after the drill', $event->reason);
        self::assertSame(['method' => 'new_pin', 'pin_version' => 4, 'source' => 'hub_admin'], $event->metadata);
        $row = json_encode(DB::table('security_action_events')->where('id', $event->id)->first(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::NEW_PIN, $row);
        self::assertStringNotContainsString(substr($hash, 7), $row);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidSubmissions(): array
    {
        return [
            'too short' => [['pin' => '123', 'pin_confirmation' => '123'], 'pin'],
            'too long' => [['pin' => '123456789', 'pin_confirmation' => '123456789'], 'pin'],
            'not digits' => [['pin' => '12a4', 'pin_confirmation' => '12a4'], 'pin'],
            'confirmation mismatch' => [['pin_confirmation' => '482914'], 'pin_confirmation'],
            'missing password' => [['current_password' => ''], 'current_password'],
            'missing reason' => [['reason' => ''], 'reason'],
            'reason too long' => [['reason' => str_repeat('r', 256)], 'reason'],
        ];
    }

    /** @param array<string, string> $overrides */
    #[DataProvider('invalidSubmissions')]
    public function test_invalid_submissions_are_rejected_before_calling_the_board(array $overrides, string $field): void
    {
        $this->fakeBoard();
        $this->actingAs($this->superAdmin());

        Livewire::test(CommandBoardPin::class)
            ->callAction('setPin', data: array_merge($this->setPinData(), $overrides))
            ->assertHasActionErrors([$field]);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_wrong_current_password_is_rejected_and_audited_as_denied(): void
    {
        $this->fakeBoard();
        $this->actingAs($this->superAdmin());

        Livewire::test(CommandBoardPin::class)
            ->callAction('setPin', data: array_merge($this->setPinData(), ['current_password' => 'not-my-password']))
            ->assertHasActionErrors(['current_password']);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
        $event = SecurityActionEvent::query()->where('action', CommandBoardPinAdministration::AUDIT_ACTION)->sole();
        self::assertSame('denied', $event->result);
        self::assertSame(['method' => 'new_pin'], $event->metadata);
    }

    public function test_repeated_wrong_passwords_are_throttled_even_for_the_right_password(): void
    {
        $this->fakeBoard();
        $this->actingAs($this->superAdmin());
        $component = Livewire::test(CommandBoardPin::class);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component->callAction('setPin', data: array_merge($this->setPinData(), ['current_password' => 'wrong-'.$attempt]))
                ->assertHasActionErrors(['current_password']);
        }
        $component->callAction('setPin', data: $this->setPinData())->assertHasActionErrors(['current_password']);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
        self::assertSame(5, SecurityActionEvent::query()->where('result', 'denied')->count());
    }

    public function test_capability_revoked_after_the_page_loaded_blocks_the_change(): void
    {
        $this->fakeBoard();
        $member = $this->member(['admin.access', 'app.cmd.admin']);
        $this->actingAs($member);
        $component = Livewire::test(CommandBoardPin::class);

        $member->revokePermissionTo('app.cmd.admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $component->call('mountAction', 'setPin')->assertForbidden();
        try {
            app(CommandBoardPinAdministration::class)->setNewPin($member, self::NEW_PIN, self::PASSWORD, 'Should be refused');
            self::fail('A revoked capability must not change the Command Board PIN.');
        } catch (AuthorizationException) {
            self::assertSame('denied', SecurityActionEvent::query()->where('action', CommandBoardPinAdministration::AUDIT_ACTION)->sole()->result);
        }
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_using_the_camera_pin_copies_its_existing_hash_once(): void
    {
        $cameraHash = $this->cameraHash();
        config()->set('video-conferencing.command_pin_hash', $cameraHash);
        $this->fakeBoard();
        $this->actingAs($this->superAdmin());

        Livewire::test(CommandBoardPin::class)
            ->assertActionEnabled('useCameraPin')
            ->assertSee('one-time copy')
            ->callAction('useCameraPin', data: ['current_password' => self::PASSWORD, 'reason' => 'Start the board on the 300 PIN'])
            ->assertHasNoActionErrors()
            ->assertNotified('Command Board PIN now matches the 300 camera PIN');

        self::assertSame($cameraHash, $this->sentPut()['pinHash']);
        $event = SecurityActionEvent::query()->where('action', CommandBoardPinAdministration::AUDIT_ACTION)->sole();
        self::assertSame('allowed', $event->result);
        self::assertSame('copied_300_camera_pin', $event->metadata['method']);
        self::assertStringNotContainsString(substr($cameraHash, 7), (string) json_encode($event->metadata));
        self::assertSame($cameraHash, config('video-conferencing.command_pin_hash'));
    }

    /** @return array<string, array{string|null, string}> */
    public static function unusableCameraHashes(): array
    {
        return [
            'not configured' => [null, 'The 300 camera PIN is not set in the Hub'],
            'cost below the board minimum' => ['$2y$04$'.str_repeat('a', 53), 'does not accept'],
            'not bcrypt' => ['$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$aGFzaA', 'does not accept'],
        ];
    }

    #[DataProvider('unusableCameraHashes')]
    public function test_camera_pin_action_is_disabled_with_an_explanation(?string $hash, string $explanation): void
    {
        config()->set('video-conferencing.command_pin_hash', $hash);
        $this->fakeBoard();
        $this->actingAs($this->superAdmin());

        Livewire::test(CommandBoardPin::class)
            ->assertActionDisabled('useCameraPin')
            ->assertSee($explanation)
            ->callAction('useCameraPin', data: ['current_password' => self::PASSWORD, 'reason' => 'Try anyway']);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    /** @return array<string, array{mixed, string, string, string}> */
    public static function boardFailures(): array
    {
        return [
            '401 bad token' => [['status' => 401, 'body' => ['error' => 'unauthorized']], 'Command Board PIN not changed', 'did not accept the Hub\'s service token', 'token_rejected'],
            '503 board token missing' => [['status' => 503, 'body' => ['error' => 'service_token_not_configured']], 'Command Board PIN not changed', 'has no service token set yet', 'board_token_missing'],
            '503 unavailable' => [['status' => 503, 'body' => ['error' => 'maintenance']], 'Command Board PIN not changed', 'temporarily unavailable', 'unavailable'],
            '422 invalid hash' => [['status' => 422, 'body' => ['error' => 'invalid_pin_hash']], 'Command Board PIN not changed', 'rejected the PIN hash', 'hash_rejected'],
            '429 rate limited' => [['status' => 429, 'body' => ['error' => 'rate_limited']], 'Command Board PIN not changed', 'limiting requests', 'rate_limited'],
            '500 unexpected' => [['status' => 500, 'body' => 'oops'], 'Could not confirm the Command Board PIN change', 'unexpected error (HTTP 500)', 'unexpected'],
            'unreadable success' => [['status' => 200, 'body' => ['ok' => true]], 'Could not confirm the Command Board PIN change', 'could not read', 'invalid_response'],
            'timeout' => ['cURL error 28: Operation timed out after 10001 milliseconds', 'Could not confirm the Command Board PIN change', 'did not respond within 10 seconds', 'timed_out'],
            'network' => ['cURL error 7: Failed to connect to cmd.example.test port 443', 'Command Board PIN not changed', 'could not connect', 'unreachable'],
        ];
    }

    /** @param array{status: int, body: mixed}|string $failure A response, or a connection error message. */
    #[DataProvider('boardFailures')]
    public function test_board_failures_are_explained_audited_and_never_log_the_pin_or_hash(array|string $failure, string $title, string $body, string $kind): void
    {
        $this->fakeBoard(putFailure: $failure);
        Log::spy();
        $this->actingAs($this->superAdmin());

        $component = Livewire::test(CommandBoardPin::class)->callAction('setPin', data: $this->setPinData());

        $this->assertNotificationBody($body);
        $component->assertNotified($title);
        $event = SecurityActionEvent::query()->where('action', CommandBoardPinAdministration::AUDIT_ACTION)->sole();
        self::assertSame('failed', $event->result);
        self::assertSame($kind, $event->metadata['error']);
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
            $encoded = (string) json_encode($context);

            return str_starts_with($message, 'command_board.pin.')
                && ! str_contains($encoded, self::NEW_PIN)
                && ! str_contains($encoded, '$2y$')
                && ! str_contains($encoded, self::TOKEN);
        });
    }

    /** @return array<string, string> */
    private function setPinData(): array
    {
        return [
            'pin' => self::NEW_PIN,
            'pin_confirmation' => self::NEW_PIN,
            'current_password' => self::PASSWORD,
            'reason' => 'Rotating after the drill',
        ];
    }

    private function cameraHash(): string
    {
        return Hash::driver('bcrypt')->make('3003', ['rounds' => 10]);
    }

    /** @param list<string> $permissions */
    private function member(array $permissions = []): User
    {
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'password' => self::PASSWORD,
            'name' => 'Pat Example',
            'display_name' => 'Capt. Pat Example',
        ]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }

    private function superAdmin(): User
    {
        $user = $this->member();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $user;
    }

    /**
     * @param  array<string, mixed>  $status
     * @param  array<string, mixed>|null  $put
     * @param  array{status: int, body: mixed}|string|null  $putFailure
     */
    private function fakeBoard(array $status = [], ?array $put = null, array|string|null $putFailure = null): void
    {
        $current = array_merge(['configured' => true, 'source' => 'hub_admin', 'pinVersion' => 3, 'updatedAt' => '2026-10-19T14:05:00Z', 'updatedBy' => 'Capt. Pat Example'], $status);
        Http::fake(function (Request $request) use ($current, $put, $putFailure) {
            if ($request->method() !== 'PUT') {
                return Http::response($current);
            }
            if (is_string($putFailure)) {
                throw new ConnectionException($putFailure);
            }
            if (is_array($putFailure)) {
                return Http::response($putFailure['body'], $putFailure['status']);
            }

            return Http::response($put ?? array_merge($current, ['pinVersion' => 4]));
        });
    }

    /** @return array<string, mixed> */
    private function sentPut(): array
    {
        $puts = Http::recorded(fn (Request $request): bool => $request->method() === 'PUT');
        self::assertCount(1, $puts);

        return $puts->first()[0]->data();
    }

    private function assertNotificationBody(string $expected): void
    {
        $bodies = array_map(static fn (array $notification): string => (string) ($notification['body'] ?? ''), (array) session('filament.notifications', []));
        foreach ($bodies as $body) {
            if (str_contains($body, $expected)) {
                self::assertStringNotContainsString(self::NEW_PIN, $body);

                return;
            }
        }
        self::fail('No notification body contains "'.$expected.'". Bodies: '.implode(' | ', $bodies));
    }
}
