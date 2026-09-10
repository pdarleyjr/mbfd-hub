<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PersonnelImportSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_reimport_updates_profile_without_resetting_existing_password(): void
    {
        $employee = Employee::create([
            'employee_id' => '20731',
            'name' => 'Existing Name',
            'rank' => 'Firefighter',
            'password' => 'existing-private-password',
            'must_change_password' => false,
        ]);
        $originalHash = $employee->password;

        $path = tempnam(sys_get_temp_dir(), 'mbfd-personnel-');
        file_put_contents($path, "Name,Rank,Employee ID\nUpdated Name,Lieutenant,20731\n");

        try {
            $this->artisan('mbfd:import-personnel', ['file' => $path])
                ->assertSuccessful()
                ->expectsOutputToContain('Updated profiles: 1');
        } finally {
            @unlink($path);
        }

        $employee->refresh();
        $this->assertSame('Updated Name', $employee->name);
        $this->assertSame('Lieutenant', $employee->rank);
        $this->assertSame($originalHash, $employee->password);
        $this->assertFalse($employee->must_change_password);
        self::assertSame('pending_activation', $employee->user()->sole()->getRawOriginal('account_status'));
    }

    public function test_new_employee_import_creates_a_pending_canonical_user_without_exposing_or_issuing_a_password(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-personnel-');
        file_put_contents($path, "Name,Rank,Employee ID\nNew Employee,Firefighter,30001\n");

        try {
            $this->artisan('mbfd:import-personnel', ['file' => $path])
                ->assertSuccessful()
                ->doesntExpectOutputToContain('password');
        } finally {
            @unlink($path);
        }

        $employee = Employee::where('employee_id', '30001')->sole();
        $this->assertFalse(Hash::check('test-owner-approved-bootstrap', $employee->getAuthPassword()));
        $this->assertTrue($employee->must_change_password);
        $user = $employee->user()->sole();
        self::assertSame('pending_activation', $user->getRawOriginal('account_status'));
        self::assertTrue($user->must_change_password);
        self::assertTrue($user->hasRole('member'));
        self::assertNull($user->temporary_credential_fingerprint);
        self::assertSame(['member'], $user->getRoleNames()->all());
    }

    public function test_new_employee_import_does_not_depend_on_a_shared_bootstrap_secret(): void
    {
        config(['security.employee_bootstrap.secret' => null]);
        $path = tempnam(sys_get_temp_dir(), 'mbfd-personnel-');
        file_put_contents($path, "Name,Rank,Employee ID\nNew Employee,Firefighter,30002\n");

        try {
            $this->artisan('mbfd:import-personnel', ['file' => $path])->assertSuccessful();
        } finally {
            @unlink($path);
        }

        $employee = Employee::query()->where('employee_id', '30002')->sole();
        self::assertSame(1, User::query()->where('employee_profile_id', $employee->id)->count());
    }
}
