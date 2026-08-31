<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ProvisionWorkgroupMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_credential_options_are_removed_and_rejected_before_membership_work(): void
    {
        $this->createActiveWorkgroup();
        $definition = Artisan::all()['mbfd:provision-workgroup-members']->getDefinition();

        self::assertFalse($definition->hasOption('password'));
        self::assertFalse($definition->hasOption('password-env'));

        foreach (['--password', '--password-env'] as $option) {
            try {
                Artisan::call('mbfd:provision-workgroup-members', [$option => 'credential-fixture-that-must-be-rejected']);
                self::fail("{$option} was unexpectedly accepted.");
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('does not exist', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('workgroup_members', 0);
    }

    public function test_existing_user_is_enrolled_without_authentication_changes(): void
    {
        $workgroup = $this->createActiveWorkgroup();
        $originalHash = Hash::make('existing-user-password');
        $user = User::factory()->create([
            'email' => 'DavidGarcia@miamibeachfl.gov',
            'password' => $originalHash,
            'must_change_password' => false,
        ]);

        $this->artisan('mbfd:provision-workgroup-members')
            ->assertExitCode(Command::SUCCESS);

        $user->refresh();

        self::assertSame($originalHash, $user->password);
        self::assertFalse($user->must_change_password);
        self::assertTrue($user->hasRole('workgroup_member'));
        $this->assertDatabaseHas('workgroup_members', [
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => 'member',
            'is_active' => true,
            'count_evaluations' => true,
        ]);
    }

    public function test_existing_workgroup_member_remains_unchanged_and_idempotent(): void
    {
        $workgroup = $this->createActiveWorkgroup();
        $originalHash = Hash::make('existing-member-password');
        $user = User::factory()->create([
            'email' => 'AlexanderNunez@miamibeachfl.gov',
            'password' => $originalHash,
            'must_change_password' => true,
        ]);
        $user->assignRole('workgroup_member');
        $membership = WorkgroupMember::query()->create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => 'facilitator',
            'is_active' => false,
            'count_evaluations' => false,
        ]);

        $this->artisan('mbfd:provision-workgroup-members')
            ->assertExitCode(Command::SUCCESS);

        $user->refresh();
        $membership->refresh();

        self::assertSame($originalHash, $user->password);
        self::assertTrue($user->must_change_password);
        self::assertSame('facilitator', $membership->role);
        self::assertFalse($membership->is_active);
        self::assertFalse($membership->count_evaluations);
        $this->assertDatabaseCount('workgroup_members', 1);
    }

    public function test_protected_user_is_not_changed_enrolled_or_stripped_of_privilege(): void
    {
        $workgroup = $this->createActiveWorkgroup();
        Role::findOrCreate('admin');
        $originalHash = Hash::make('protected-user-password');
        $protected = User::factory()->create([
            'email' => 'DavidGarcia@miamibeachfl.gov',
            'password' => $originalHash,
            'must_change_password' => false,
        ]);
        $protected->assignRole('admin');

        $this->artisan('mbfd:provision-workgroup-members')
            ->assertExitCode(Command::SUCCESS);

        $protected->refresh();

        self::assertSame($originalHash, $protected->password);
        self::assertFalse($protected->must_change_password);
        self::assertTrue($protected->hasRole('admin'));
        self::assertFalse($protected->hasRole('workgroup_member'));
        $this->assertDatabaseMissing('workgroup_members', [
            'workgroup_id' => $workgroup->id,
            'user_id' => $protected->id,
        ]);
    }

    public function test_missing_users_are_reported_and_not_created(): void
    {
        $this->createActiveWorkgroup();
        $userCountBefore = User::query()->count();

        $this->artisan('mbfd:provision-workgroup-members')
            ->expectsOutputToContain('canonical User does not exist; provision identity through approved identity workflow')
            ->assertExitCode(Command::SUCCESS);

        self::assertSame($userCountBefore, User::query()->count());
        $this->assertDatabaseMissing('users', [
            'email' => 'davidgarcia@miamibeachfl.gov',
        ]);
        $this->assertDatabaseCount('workgroup_members', 0);
    }

    public function test_multiple_users_keep_their_distinct_existing_credentials(): void
    {
        $workgroup = $this->createActiveWorkgroup();
        $firstHash = Hash::make('first-distinct-password');
        $secondHash = Hash::make('second-distinct-password');
        $first = User::factory()->create([
            'email' => 'DavidGarcia@miamibeachfl.gov',
            'password' => $firstHash,
            'must_change_password' => false,
        ]);
        $second = User::factory()->create([
            'email' => 'MillyesGomez@miamibeachfl.gov',
            'password' => $secondHash,
            'must_change_password' => true,
        ]);

        $this->artisan('mbfd:provision-workgroup-members')
            ->assertExitCode(Command::SUCCESS);

        $first->refresh();
        $second->refresh();

        self::assertSame($firstHash, $first->password);
        self::assertSame($secondHash, $second->password);
        self::assertNotSame($first->password, $second->password);
        self::assertFalse($first->must_change_password);
        self::assertTrue($second->must_change_password);
        self::assertTrue($first->hasRole('workgroup_member'));
        self::assertTrue($second->hasRole('workgroup_member'));
        $this->assertDatabaseCount('workgroup_members', 2);
        $this->assertDatabaseHas('workgroup_members', [
            'workgroup_id' => $workgroup->id,
            'user_id' => $first->id,
        ]);
        $this->assertDatabaseHas('workgroup_members', [
            'workgroup_id' => $workgroup->id,
            'user_id' => $second->id,
        ]);
    }

    private function createActiveWorkgroup(): Workgroup
    {
        Role::findOrCreate('workgroup_member');
        $owner = User::factory()->create();

        return Workgroup::query()->create([
            'name' => 'Active test workgroup',
            'is_active' => true,
            'created_by' => $owner->id,
        ]);
    }
}
