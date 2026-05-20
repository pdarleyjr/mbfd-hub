<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SyncToScreentinkerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.screentinker.sync_url' => 'http://host.docker.internal:8095/api/admin/users/sync',
            'services.screentinker.sync_token' => 'test-token-fixture',
        ]);

        foreach (['super_admin', 'admin', 'logistics_admin', 'training_admin', 'workgroup_member'] as $name) {
            Role::findOrCreate($name, 'web');
        }
    }

    public function test_admin_user_password_change_dispatches_sync_call(): void
    {
        Http::fake([
            '*' => Http::response(['user_id' => 'u-1', 'action' => 'created'], 200),
        ]);

        $user = User::factory()->create(['email' => 'admin1@miamibeachfl.gov']);
        $user->assignRole('admin');

        $user->password = 'Penco3';
        $user->save();

        Http::assertSent(function ($req) {
            return $req->url() === 'http://host.docker.internal:8095/api/admin/users/sync'
                && $req->method() === 'POST'
                && $req->hasHeader('Authorization', 'Bearer test-token-fixture')
                && data_get($req->data(), 'email') === 'admin1@miamibeachfl.gov'
                && data_get($req->data(), 'password') === 'Penco3'
                && data_get($req->data(), 'role') === 'platform_admin';
        });
    }

    public function test_non_admin_user_password_change_does_not_dispatch_sync(): void
    {
        Http::fake();

        $user = User::factory()->create(['email' => 'wg@example.com']);
        $user->assignRole('workgroup_member');

        $user->password = 'newpass123';
        $user->save();

        Http::assertNothingSent();
    }

    public function test_admin_save_with_no_password_change_does_not_dispatch(): void
    {
        Http::fake();

        $user = User::factory()->create(['email' => 'admin2@miamibeachfl.gov']);
        $user->assignRole('admin');

        // Touch a non-password attribute and save.
        $user->name = 'Renamed';
        $user->save();

        Http::assertNothingSent();
    }

    public function test_missing_config_skips_sync_silently(): void
    {
        Http::fake();
        config([
            'services.screentinker.sync_url' => null,
            'services.screentinker.sync_token' => null,
        ]);

        $user = User::factory()->create(['email' => 'admin3@miamibeachfl.gov']);
        $user->assignRole('admin');

        $user->password = 'Penco3';
        $user->save();

        Http::assertNothingSent();
    }

    public function test_sync_failure_does_not_block_user_save(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'screentinker container is down'], 503),
        ]);

        $user = User::factory()->create(['email' => 'admin4@miamibeachfl.gov']);
        $user->assignRole('admin');

        $user->password = 'newpass99';
        // Should not throw despite the 503.
        $user->save();

        $this->assertTrue(true);
        Http::assertSentCount(1);
    }

    public function test_new_user_created_with_password_then_role_attached_dispatches_sync(): void
    {
        // Reproduces the Filament flow: User::create([... password ...]),
        // followed by assignRole() in the same request. saved() fires
        // BEFORE the role is attached, so the RoleAttached listener is
        // what actually catches this case.
        Http::fake([
            '*' => Http::response(['user_id' => 'u-new', 'action' => 'created'], 200),
        ]);

        $user = User::create([
            'name' => 'Brand New Admin',
            'email' => 'newadmin@miamibeachfl.gov',
            'password' => 'Penco3',
        ]);
        $user->assignRole('admin');

        Http::assertSentCount(1);
        Http::assertSent(function ($req) {
            return data_get($req->data(), 'email') === 'newadmin@miamibeachfl.gov'
                && data_get($req->data(), 'password') === 'Penco3';
        });
    }
}
