<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\User;
use App\Services\Identity\MemberOnboardingInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class GoLiveCapacityProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_disposable_300_member_read_capacity(): void
    {
        $this->withoutVite();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        for ($index = 1; $index <= 300; $index++) {
            $employeeId = sprintf('CAP-%04d', $index);
            $email = strtolower($employeeId).'@miamibeachfl.gov';
            $employee = Employee::query()->create([
                'employee_id' => $employeeId,
                'name' => 'Capacity Test Member',
                'roster_status' => 'active',
                'city_email' => $email,
            ]);
            MemberOnboardingRosterBinding::query()->create([
                'employee_profile_id' => $employee->id,
                'employee_id' => $employeeId,
                'city_email' => $email,
                'source_sha256' => str_repeat('a', 64),
                'approved_at' => now(),
            ]);
            User::factory()->create([
                'employee_profile_id' => $employee->id,
                'employee_id' => $employeeId,
                'email' => 'pending-'.strtolower($employeeId).'@canonical.mbfdhub.invalid',
                'account_status' => AccountStatus::PendingActivation,
                'bootstrap_onboarding_eligible' => true,
                'bootstrap_onboarding_eligible_at' => now(),
                'security_version' => 1,
            ]);
        }

        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $started = hrtime(true);
        $employees = Employee::query()
            ->whereHas('user', fn ($query) => $query->where('account_status', AccountStatus::PendingActivation->value))
            ->with('user')->orderBy('employee_id')->get();
        $assessments = app(MemberOnboardingInvitationService::class)->assessMany($employees);
        $previewMs = (hrtime(true) - $started) / 1_000_000;
        $previewQueries = $queries;

        $started = hrtime(true);
        $this->get('/admin/employees')->assertOk();
        $adminMs = (hrtime(true) - $started) / 1_000_000;
        $adminQueries = $queries - $previewQueries;

        fwrite(STDERR, sprintf(
            "CAPACITY rows=%d preview_queries=%d preview_ms=%.1f admin_queries=%d admin_ms=%.1f peak_mib=%.1f\n",
            count($assessments), $previewQueries, $previewMs, $adminQueries, $adminMs, memory_get_peak_usage(true) / 1048576,
        ));
        self::assertCount(300, $assessments);
        self::assertLessThanOrEqual(20, $previewQueries);
        self::assertLessThanOrEqual(30, $adminQueries);
    }
}
