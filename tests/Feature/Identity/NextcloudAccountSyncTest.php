<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\Employee;
use App\Models\NextcloudAccessSync;
use App\Models\OidcAccountLink;
use App\Models\User;
use App\Services\Cloud\NextcloudAccountSynchronizer;
use App\Services\Cloud\NextcloudIdentityBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class NextcloudAccountSyncTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        $employee = Employee::create(['name' => 'Cloud Fixture', 'employee_id' => 'CLOUD-99001', 'password' => bcrypt('Test-only-password!')]);
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id, 'account_status' => 'active', 'must_change_password' => false, 'security_version' => 3]);
        $user->givePermissionTo(Permission::findOrCreate('app.cloud.access', 'web'));
        OidcAccountLink::create(['application' => 'cloud', 'user_id' => $user->id, 'employee_profile_id' => $employee->id, 'external_uid' => 'fixturecloud']);

        return $user;
    }

    public function test_permission_and_security_changes_are_durable_without_network_in_account_transaction(): void
    {
        $user = $this->member();
        $sync = app(NextcloudAccountSynchronizer::class);
        $sync->request($user);
        $row = NextcloudAccessSync::sole();
        $this->assertTrue($row->desired_enabled);
        $this->assertSame(1, $row->requested_revision);
        $this->assertSame(0, $row->applied_revision);
        $sync->request($user);
        $this->assertSame(1, $row->fresh()->requested_revision);
        $user->revokePermissionTo('app.cloud.access');
        $sync->request($user);
        $this->assertFalse($row->fresh()->desired_enabled);
        $this->assertSame(2, $row->fresh()->requested_revision);
        $user->forceFill(['security_version' => 4])->save();
        $sync->request($user);
        $this->assertSame(3, $row->fresh()->requested_revision);
    }

    public function test_only_exact_existing_mapping_is_managed(): void
    {
        $user = User::factory()->create();
        app(NextcloudAccountSynchronizer::class)->request($user);
        $this->assertDatabaseCount('nextcloud_access_syncs', 0);
    }

    public function test_changed_canonical_profile_or_required_password_blocks_cloud(): void
    {
        $user = $this->member();
        $user->forceFill(['must_change_password' => true])->save();
        $sync = app(NextcloudAccountSynchronizer::class);
        $sync->request($user);
        $this->assertFalse(NextcloudAccessSync::sole()->desired_enabled);
        $other = Employee::create(['name' => 'Other Fixture', 'employee_id' => 'CLOUD-99002', 'password' => bcrypt('Test-only-password!')]);
        $user->forceFill(['must_change_password' => false, 'employee_profile_id' => $other->id])->save();
        $sync->request($user);
        $this->assertFalse(NextcloudAccessSync::sole()->desired_enabled);
    }

    public function test_reconcile_requires_exact_uid_revision_enabled_and_purged_token_acknowledgment(): void
    {
        config(['nextcloud_identity.enabled' => true]);
        $user = $this->member();
        $bridge = Mockery::mock(NextcloudIdentityBridge::class);
        $bridge->shouldReceive('reconcile')->once()->with('fixturecloud', 1, true)->andReturn(['uid' => 'fixturecloud', 'revision' => 1, 'enabled' => true, 'old_tokens_purged' => true]);
        $this->app->instance(NextcloudIdentityBridge::class, $bridge);
        $sync = app(NextcloudAccountSynchronizer::class);
        $sync->request($user);
        $this->assertTrue($sync->synchronize($user->id));
        $row = NextcloudAccessSync::sole();
        $this->assertSame(1, $row->applied_revision);
        $this->assertNotNull($row->verified_at);
        $this->assertNull($row->last_error);
    }

    public function test_failed_remote_acknowledgment_remains_pending_and_does_not_undo_hub_revocation(): void
    {
        config(['nextcloud_identity.enabled' => true]);
        $user = $this->member();
        $user->revokePermissionTo('app.cloud.access');
        $bridge = Mockery::mock(NextcloudIdentityBridge::class);
        $bridge->shouldReceive('reconcile')->once()->andThrow(new RuntimeException('Sensitive remote failure should not be persisted'));
        $this->app->instance(NextcloudIdentityBridge::class, $bridge);
        $sync = app(NextcloudAccountSynchronizer::class);
        $sync->request($user);
        $this->assertFalse($sync->synchronize($user->id));
        $row = NextcloudAccessSync::sole();
        $this->assertSame(0, $row->applied_revision);
        $this->assertFalse($row->desired_enabled);
        $this->assertSame('Cloud account synchronization is pending; remote enforcement was not verified.', $row->last_error);
        $this->assertFalse($user->fresh()->hasDirectWebPermission('app.cloud.access'));
    }

    public function test_mismatched_remote_account_is_not_accepted(): void
    {
        config(['nextcloud_identity.enabled' => true]);
        $user = $this->member();
        $bridge = Mockery::mock(NextcloudIdentityBridge::class);
        $bridge->shouldReceive('reconcile')->once()->andReturn(['uid' => 'someoneelse', 'revision' => 1, 'enabled' => true, 'old_tokens_purged' => true]);
        $this->app->instance(NextcloudIdentityBridge::class, $bridge);
        $sync = app(NextcloudAccountSynchronizer::class);
        $sync->request($user);
        $this->assertFalse($sync->synchronize($user->id));
        $this->assertSame(0, NextcloudAccessSync::sole()->applied_revision);
    }

    public function test_removing_mapping_turns_existing_enable_intent_into_denial(): void
    {
        $user = $this->member();
        $sync = app(NextcloudAccountSynchronizer::class);
        $sync->request($user);
        OidcAccountLink::query()->delete();
        $sync->request($user);
        $row = NextcloudAccessSync::sole();
        $this->assertFalse($row->desired_enabled);
        $this->assertSame('fixturecloud', $row->external_uid);
        $this->assertSame(2, $row->requested_revision);
    }

    public function test_applied_revision_is_still_audited_for_remote_drift(): void
    {
        config(['nextcloud_identity.enabled' => true]);
        $user = $this->member();
        $bridge = Mockery::mock(NextcloudIdentityBridge::class);
        $bridge->shouldReceive('reconcile')->twice()->with('fixturecloud', 1, true)
            ->andReturn(['uid' => 'fixturecloud', 'revision' => 1, 'enabled' => true, 'old_tokens_purged' => true]);
        $this->app->instance(NextcloudIdentityBridge::class, $bridge);
        $sync = app(NextcloudAccountSynchronizer::class);
        $this->assertTrue($sync->synchronize($user->id));
        $this->assertTrue($sync->synchronize($user->id));
    }

    public function test_changed_mapping_disables_old_uid_without_following_new_uid(): void
    {
        $user = $this->member();
        $sync = app(NextcloudAccountSynchronizer::class);
        $sync->request($user);
        OidcAccountLink::sole()->forceFill(['external_uid' => 'differentaccount'])->save();
        $sync->request($user);
        $row = NextcloudAccessSync::sole();
        $this->assertFalse($row->desired_enabled);
        $this->assertSame('fixturecloud', $row->external_uid);
        $this->assertSame(2, $row->requested_revision);
    }

    public function test_failed_user_does_not_starve_later_unlinked_account_denial(): void
    {
        config(['nextcloud_identity.enabled' => true]);
        $first = $this->member();
        $second = User::factory()->create(['security_version' => 1]);
        NextcloudAccessSync::create(['user_id' => $second->id, 'external_uid' => 'unlinkedfixture',
            'desired_enabled' => true, 'security_version' => 1, 'requested_revision' => 1, 'applied_revision' => 1]);
        $bridge = Mockery::mock(NextcloudIdentityBridge::class);
        $bridge->shouldReceive('reconcile')->once()->with('fixturecloud', 1, true)->andThrow(new RuntimeException('offline'));
        $bridge->shouldReceive('reconcile')->once()->with('unlinkedfixture', 2, false)
            ->andReturn(['uid' => 'unlinkedfixture', 'revision' => 2, 'enabled' => false, 'old_tokens_purged' => true]);
        $this->app->instance(NextcloudIdentityBridge::class, $bridge);
        $this->artisan('mbfd:nextcloud-access-sync')->assertFailed();
        $this->assertSame(2, NextcloudAccessSync::where('user_id', $second->id)->sole()->applied_revision);
    }
}
