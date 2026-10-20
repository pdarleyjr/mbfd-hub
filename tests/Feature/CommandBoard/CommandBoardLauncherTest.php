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

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.command_board.url' => self::URL,
            'application_access.runtime_verified.cmd' => true,
        ]);
    }

    public function test_shared_launcher_never_lists_command_board_regardless_of_access_or_configuration(): void
    {
        $user = $this->actingAsCanonicalFixture();
        $allowed = ['cmd' => ['allowed' => true]];

        self::assertNotContains('cmd', $this->keys($user, $allowed));
        self::assertNotContains('cmd', $this->keys($user, ['cmd' => ['allowed' => false]]));

        config(['application_access.runtime_verified.cmd' => false]);
        self::assertNotContains('cmd', $this->keys($user, $allowed));

        config(['application_access.runtime_verified.cmd' => true, 'services.command_board.url' => 'http://cmd.mbfdhub.com']);
        self::assertNotContains('cmd', $this->keys($user, $allowed));
    }

    public function test_home_and_shared_launcher_omit_command_board_for_every_account_type(): void
    {
        $this->withoutVite();
        $this->configureClient();

        $superAdmin = $this->actingAsCanonicalFixture('E01-CMD-SUPER');
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

        $direct = $this->actingAsCanonicalFixture('E01-CMD-DIRECT');
        $direct->givePermissionTo(Permission::findOrCreate('app.cmd.access', 'web'));

        $inherited = $this->actingAsCanonicalFixture('E01-CMD-ROLE');
        $role = Role::findOrCreate('cmd-launcher-role-test', 'web');
        $role->givePermissionTo(Permission::findOrCreate('app.cmd.access', 'web'));
        $inherited->assignRole($role);
        $plain = $this->actingAsCanonicalFixture('E01-CMD-PLAIN');
        foreach ([$plain, $direct, $inherited, $superAdmin] as $user) {
            $this->actingAsCanonicalUser($user->fresh());
            self::assertNotContains('cmd', $this->keys($user->fresh()));
            $this->get('/')->assertOk()
                ->assertSee('Quick Access')
                ->assertDontSee('Command Board')
                ->assertDontSee('href="'.self::URL.'"', false);
        }
    }

    public function test_configuring_the_cmd_client_does_not_add_a_home_link(): void
    {
        $this->withoutVite();
        $user = $this->actingAsCanonicalFixture();
        $user->givePermissionTo(Permission::findOrCreate('app.cmd.access', 'web'));

        self::assertNotContains('cmd', $this->keys($user->fresh()));
        $this->get('/')->assertOk()->assertDontSee('href="'.self::URL.'"', false);
        $this->configureClient();
        self::assertNotContains('cmd', $this->keys($user->fresh()));
        $this->get('/')->assertOk()->assertDontSee('href="'.self::URL.'"', false);
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
