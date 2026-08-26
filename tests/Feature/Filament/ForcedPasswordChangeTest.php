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
}
