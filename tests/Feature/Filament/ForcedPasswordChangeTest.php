<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\SetPasswordPage;
use App\Http\Middleware\ForcePasswordChange;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function panelRoutes(): array
    {
        return [
            'admin' => ['admin', '/admin', '/admin/set-password', 'admin'],
            'training' => ['training_admin', '/training', '/training/set-password', 'training'],
            'workgroups' => ['workgroup_member', '/workgroups', '/workgroups/set-password', 'workgroups'],
        ];
    }

    #[DataProvider('panelRoutes')]
    public function test_flagged_users_are_redirected_to_their_current_panel_password_page(
        string $role,
        string $homeUrl,
        string $setPasswordUrl,
        string $panelId,
    ): void {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole(Role::findOrCreate($role, 'web'));

        $this->actingAs($user)
            ->get($homeUrl)
            ->assertRedirect($setPasswordUrl);

        $this->actingAs($user)
            ->get($setPasswordUrl)
            ->assertOk()
            ->assertSee('Password Change Required');

        self::assertSame($panelId, Filament::getPanel($panelId)->getId());
    }

    #[DataProvider('panelRoutes')]
    public function test_unflagged_users_keep_access_to_their_current_panel(
        string $role,
        string $homeUrl,
        string $setPasswordUrl,
        string $panelId,
    ): void {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole(Role::findOrCreate($role, 'web'));

        $this->actingAs($user)
            ->get($homeUrl)
            ->assertOk();

        self::assertSame($panelId, Filament::getPanel($panelId)->getId());
        self::assertNotSame($setPasswordUrl, $homeUrl);
    }

    public function test_password_update_keeps_the_session_valid_and_does_not_send_a_screentinker_request(): void
    {
        Http::fake();
        config([
            'services.screentinker.sync_url' => 'https://screen-sync.invalid/api/users',
            'services.screentinker.sync_token' => 'test-token',
        ]);

        $user = User::factory()->create([
            'must_change_password' => true,
            'password' => Hash::make('current-password'),
        ]);
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SetPasswordPage::class)
            ->fillForm([
                'current_password' => 'current-password',
                'password' => 'A-longer-replacement-password-2026',
                'password_confirmation' => 'A-longer-replacement-password-2026',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $user->refresh();

        self::assertFalse($user->must_change_password);
        self::assertTrue(Hash::check('A-longer-replacement-password-2026', $user->password));
        self::assertSame($user->password, session('password_hash_web'));
        Http::assertNothingSent();
    }

    public function test_password_update_rejects_an_invalid_current_password(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'password' => Hash::make('current-password'),
        ]);
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SetPasswordPage::class)
            ->fillForm([
                'current_password' => 'incorrect-password',
                'password' => 'A-longer-replacement-password-2026',
                'password_confirmation' => 'A-longer-replacement-password-2026',
            ])
            ->call('save')
            ->assertHasFormErrors(['current_password']);

        $user->refresh();

        self::assertTrue($user->must_change_password);
        self::assertTrue(Hash::check('current-password', $user->password));
    }

    public function test_legacy_web_password_middleware_does_not_hijack_a_livewire_update(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $this->actingAs($user);

        $request = Request::create('/livewire/update', 'POST');

        $response = app(ForcePasswordChange::class)->handle(
            $request,
            fn () => response('Livewire update allowed'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Livewire update allowed', $response->getContent());
    }

    #[DataProvider('panelRoutes')]
    public function test_flagged_users_cannot_invoke_a_protected_panel_livewire_update(
        string $role,
        string $homeUrl,
        string $setPasswordUrl,
        string $panelId,
    ): void {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole(Role::findOrCreate($role, 'web'));

        $snapshot = $this->livewireSnapshotFrom(
            $this->actingAs($user)->get($homeUrl)->assertOk(),
        );

        $user->forceFill(['must_change_password' => true])->save();

        $response = $this->actingAs($user->fresh())
            ->withHeader('X-Livewire', 'true')
            ->postJson('/livewire/update', [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [[
                        'path' => '',
                        'method' => '$refresh',
                        'params' => [],
                    ]],
                ]],
            ]);

        $response->assertRedirect($setPasswordUrl);
        self::assertTrue($user->fresh()->must_change_password);
        self::assertSame($panelId, Filament::getPanel($panelId)->getId());
    }

    #[DataProvider('panelRoutes')]
    public function test_flagged_users_can_complete_password_change_through_livewire_and_regain_panel_access(
        string $role,
        string $homeUrl,
        string $setPasswordUrl,
        string $panelId,
    ): void {
        $user = User::factory()->create([
            'must_change_password' => true,
            'password' => Hash::make('current-password'),
        ]);
        $user->assignRole(Role::findOrCreate($role, 'web'));

        $snapshot = $this->livewireSnapshotFrom(
            $this->actingAs($user)->get($setPasswordUrl)->assertOk(),
            'set-password-page',
        );

        $response = $this->actingAs($user)
            ->withHeader('X-Livewire', 'true')
            ->postJson('/livewire/update', [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [
                        'data.current_password' => 'current-password',
                        'data.password' => 'A-longer-replacement-password-2026',
                        'data.password_confirmation' => 'A-longer-replacement-password-2026',
                    ],
                    'calls' => [[
                        'path' => '',
                        'method' => 'save',
                        'params' => [],
                    ]],
                ]],
            ]);

        $response->assertOk();
        self::assertFalse($user->fresh()->must_change_password);
        self::assertTrue(Hash::check('A-longer-replacement-password-2026', $user->fresh()->password));
        $this->actingAs($user->fresh())->get($homeUrl)->assertOk();
        self::assertSame($panelId, Filament::getPanel($panelId)->getId());
    }

    #[DataProvider('panelRoutes')]
    public function test_flagged_users_can_log_out_without_completing_password_change(
        string $role,
        string $homeUrl,
        string $setPasswordUrl,
        string $panelId,
    ): void {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole(Role::findOrCreate($role, 'web'));

        $this->actingAs($user)
            ->post(Filament::getPanel($panelId)->getLogoutUrl())
            ->assertRedirect();

        $this->assertGuest('web');
    }

    public function test_livewire_reapplies_the_admin_training_redirect_after_a_role_change(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        $snapshot = $this->livewireSnapshotFrom(
            $this->actingAs($user)->get('/admin')->assertOk(),
        );

        $user->syncRoles([Role::findOrCreate('training_admin', 'web')]);

        $this->actingAs($user->fresh())
            ->withHeader('X-Livewire', 'true')
            ->postJson('/livewire/update', [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [],
                ]],
            ])
            ->assertRedirect('/training');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function restrictedPanelRoles(): array
    {
        return [
            'training' => ['training_admin', '/training'],
            'workgroups' => ['workgroup_member', '/workgroups'],
        ];
    }

    #[DataProvider('restrictedPanelRoles')]
    public function test_livewire_rechecks_training_and_workgroup_panel_access_after_a_role_is_removed(
        string $role,
        string $homeUrl,
    ): void {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole(Role::findOrCreate($role, 'web'));

        $snapshot = $this->livewireSnapshotFrom(
            $this->actingAs($user)->get($homeUrl)->assertOk(),
        );

        $user->syncRoles([]);

        $this->actingAs($user->fresh())
            ->withHeader('X-Livewire', 'true')
            ->postJson('/livewire/update', [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [],
                ]],
            ])
            ->assertForbidden();
    }

    private function livewireSnapshotFrom(TestResponse $response, ?string $componentNameSuffix = null): string
    {
        $matched = preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);

        self::assertGreaterThan(0, $matched, 'Expected a real Livewire snapshot in the panel response.');

        $componentNames = [];

        foreach ($matches[1] as $encodedSnapshot) {
            $snapshot = html_entity_decode($encodedSnapshot, ENT_QUOTES);
            $componentName = data_get(json_decode($snapshot, true), 'memo.name');
            $componentNames[] = (string) $componentName;

            if ($componentNameSuffix === null || str_ends_with((string) $componentName, $componentNameSuffix)) {
                return $snapshot;
            }
        }

        self::fail(sprintf(
            'Expected a Livewire component ending in [%s]; found [%s].',
            $componentNameSuffix,
            implode(', ', array_unique($componentNames)),
        ));
    }
}
