<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use App\Models\Workgroup;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ProvisionWorkgroupMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_an_explicit_password_before_any_provisioning_work(): void
    {
        $this->artisan('mbfd:provision-workgroup-members')
            ->expectsOutput('Pass a unique temporary password with --password=<value> or --password-env=<VARIABLE>. No default password is available.')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('workgroup_members', 0);
    }

    public function test_it_uses_an_explicit_password_option_for_base_member_resets(): void
    {
        $owner = User::factory()->create();
        Workgroup::query()->create([
            'name' => 'Active test workgroup',
            'is_active' => true,
            'created_by' => $owner->id,
        ]);
        Role::findOrCreate('workgroup_member');

        $member = User::factory()->create();
        $member->assignRole('workgroup_member');
        $password = Str::random(24);

        $this->artisan('mbfd:provision-workgroup-members', ['--password' => $password])
            ->assertExitCode(Command::SUCCESS);

        $this->assertTrue(Hash::check($password, $member->fresh()->password));
        $this->assertTrue($member->fresh()->must_change_password);
        $this->assertTrue(
            User::query()
                ->whereRaw('LOWER(email) = ?', ['davidgarcia@miamibeachfl.gov'])
                ->sole()
                ->must_change_password,
        );
    }

    public function test_it_uses_an_explicit_password_environment_source_for_base_member_resets(): void
    {
        $owner = User::factory()->create();
        Workgroup::query()->create([
            'name' => 'Active test workgroup',
            'is_active' => true,
            'created_by' => $owner->id,
        ]);
        Role::findOrCreate('workgroup_member');

        $member = User::factory()->create();
        $member->assignRole('workgroup_member');
        $password = Str::random(24);
        $variable = 'MBFD_TEST_WORKGROUP_PROVISION_PASSWORD';
        $previous = getenv($variable);
        putenv("{$variable}={$password}");

        try {
            $this->artisan('mbfd:provision-workgroup-members', ['--password-env' => $variable])
                ->assertExitCode(Command::SUCCESS);
        } finally {
            if ($previous === false) {
                putenv($variable);
            } else {
                putenv("{$variable}={$previous}");
            }
        }

        $this->assertTrue(Hash::check($password, $member->fresh()->password));
        $this->assertTrue($member->fresh()->must_change_password);
    }

    public function test_it_does_not_reset_or_enroll_a_protected_account_that_matches_a_new_member_email(): void
    {
        $owner = User::factory()->create();
        $workgroup = Workgroup::query()->create([
            'name' => 'Active test workgroup',
            'is_active' => true,
            'created_by' => $owner->id,
        ]);
        Role::findOrCreate('admin');
        Role::findOrCreate('workgroup_member');
        $protected = User::factory()->create([
            'email' => 'DavidGarcia@miamibeachfl.gov',
            'must_change_password' => false,
        ]);
        $protected->assignRole('admin');
        $password = Str::random(24);

        $this->artisan('mbfd:provision-workgroup-members', ['--password' => $password])
            ->assertExitCode(Command::SUCCESS);

        $protected->refresh();

        $this->assertFalse(Hash::check($password, $protected->password));
        $this->assertFalse($protected->must_change_password);
        $this->assertFalse($protected->hasRole('workgroup_member'));
        $this->assertDatabaseMissing('workgroup_members', [
            'workgroup_id' => $workgroup->id,
            'user_id' => $protected->id,
        ]);
    }
}
