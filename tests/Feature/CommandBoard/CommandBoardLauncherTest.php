<?php

declare(strict_types=1);

namespace Tests\Feature\CommandBoard;

use App\Models\User;
use App\Support\HubShellNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CommandBoardLauncherTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://cmd.mbfdhub.com';

    private const ENTRY = ['key' => 'cmd', 'label' => 'Command Board', 'href' => self::URL];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.command_board.url' => self::URL,
            'application_access.runtime_verified.cmd' => true,
        ]);
    }

    public function test_launcher_entry_requires_current_access_verified_sign_in_and_an_https_url(): void
    {
        $user = $this->actingAsCanonicalFixture();
        $allowed = ['cmd' => ['allowed' => true]];

        self::assertContains(self::ENTRY, HubShellNavigation::forUser($user, $allowed)['applications']);
        self::assertNotContains('cmd', $this->keys($user, ['cmd' => ['allowed' => false]]));

        config(['application_access.runtime_verified.cmd' => false]);
        self::assertNotContains('cmd', $this->keys($user, $allowed));

        config(['application_access.runtime_verified.cmd' => true, 'services.command_board.url' => 'http://cmd.mbfdhub.com']);
        self::assertNotContains('cmd', $this->keys($user, $allowed));
    }

    public function test_launcher_follows_the_cmd_oidc_policy_for_real_accounts(): void
    {
        $this->configureClient();

        $superAdmin = $this->actingAsCanonicalFixture('E01-CMD-SUPER');
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
        self::assertContains(self::ENTRY, HubShellNavigation::forUser($superAdmin->fresh())['applications']);

        $direct = $this->actingAsCanonicalFixture('E01-CMD-DIRECT');
        $direct->givePermissionTo(Permission::findOrCreate('app.cmd.access', 'web'));
        self::assertContains(self::ENTRY, HubShellNavigation::forUser($direct->fresh())['applications']);

        $inherited = $this->actingAsCanonicalFixture('E01-CMD-ROLE');
        $role = Role::findOrCreate('cmd-launcher-role-test', 'web');
        $role->givePermissionTo(Permission::findOrCreate('app.cmd.access', 'web'));
        $inherited->assignRole($role);
        self::assertNotContains('cmd', $this->keys($inherited->fresh()));

        $plain = $this->actingAsCanonicalFixture('E01-CMD-PLAIN');
        self::assertNotContains('cmd', $this->keys($plain->fresh()));

        $direct->forceFill(['must_change_password' => true])->save();
        self::assertNotContains('cmd', $this->keys($direct->fresh()));
    }

    public function test_launcher_hides_the_board_until_its_oidc_client_is_configured(): void
    {
        $user = $this->actingAsCanonicalFixture();
        $user->givePermissionTo(Permission::findOrCreate('app.cmd.access', 'web'));

        self::assertNotContains('cmd', $this->keys($user->fresh()));
        $this->configureClient();
        self::assertContains('cmd', $this->keys($user->fresh()));
    }

    public function test_home_quick_access_shows_the_command_board_tile_only_to_entitled_members(): void
    {
        $this->withoutVite();
        $this->configureClient();
        $plain = $this->actingAsCanonicalFixture('E01-CMD-HOME-PLAIN');

        $this->get('/')->assertOk()->assertDontSee('href="'.self::URL.'"', false);

        $member = $this->actingAsCanonicalFixture('E01-CMD-HOME');
        $member->givePermissionTo(Permission::findOrCreate('app.cmd.access', 'web'));

        $this->get('/')->assertOk()
            ->assertSeeInOrder(['Quick Access', 'Command Board'])
            ->assertSee('Sign in with your Hub account, then enter the command PIN')
            ->assertSee('href="'.self::URL.'"', false);
        self::assertNotContains('cmd', $this->keys($plain->fresh()));
    }

    private function configureClient(): void
    {
        $client = Client::query()->create([
            'name' => 'MBFD Command Board', 'secret' => 'launcher-client-test-only', 'provider' => 'users',
            'redirect_uris' => [self::URL.'/auth/callback'], 'grant_types' => ['authorization_code'], 'revoked' => false,
        ]);
        config(['oidc.clients.cmd' => $client->id]);
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $states
     * @return list<string>
     */
    private function keys(User $user, ?array $states = null): array
    {
        return array_column(HubShellNavigation::forUser($user, $states)['applications'], 'key');
    }
}
