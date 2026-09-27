<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AccountStatus;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Employee;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\Station;
use App\Models\User;
use App\Services\Identity\MemberOnboardingInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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
                'rank' => $index % 2 === 0 ? 'Driver' : 'Firefighter',
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
        $started = hrtime(true);
        $before = $queries;
        Livewire::test(ListEmployees::class)->searchTable('CAP-0150');
        $searchQueries = $queries - $before;
        fwrite(STDERR, sprintf("CAPACITY_SEARCH queries=%d ms=%.1f\n", $searchQueries, (hrtime(true) - $started) / 1_000_000));

        $started = hrtime(true);
        $before = $queries;
        Livewire::test(ListEmployees::class)->filterTable('rank', 'Firefighter');
        $filterQueries = $queries - $before;
        fwrite(STDERR, sprintf("CAPACITY_FILTER queries=%d ms=%.1f\n", $filterQueries, (hrtime(true) - $started) / 1_000_000));

        foreach (range(1, 6) as $station) {
            Station::query()->create(['station_number' => $station, 'address' => 'Synthetic test station', 'is_active' => true]);
        }
        $activeUsers = User::query()->where('employee_id', 'like', 'CAP-%')->orderBy('id')->limit(20)->get();
        foreach ($activeUsers as $user) {
            $user->forceFill(['account_status' => AccountStatus::Active])->save();
        }
        $this->actingAs($activeUsers->first());
        foreach (['/employee/dashboard', '/daily/stations', '/api/public/stations'] as $path) {
            $started = hrtime(true);
            $before = $queries;
            $response = $this->get($path);
            $response->assertOk();
            fwrite(STDERR, sprintf("CAPACITY_READ path=%s http=%d queries=%d ms=%.1f\n", $path, $response->getStatusCode(), $queries - $before, (hrtime(true) - $started) / 1_000_000));
        }
        $loginStatuses = [];
        $started = hrtime(true);
        $before = $queries;
        foreach ($activeUsers as $user) {
            $this->post('/logout');
            $response = $this->post('/login', ['employee_id' => $user->employee_id, 'password' => 'password']);
            $response->assertRedirect('/');
            $this->assertAuthenticatedAs($user);
            $loginStatuses[$response->getStatusCode()] = ($loginStatuses[$response->getStatusCode()] ?? 0) + 1;
        }
        fwrite(STDERR, sprintf("CAPACITY_LOGIN n=20 statuses=%s queries=%d ms=%.1f\n", json_encode($loginStatuses), $queries - $before, (hrtime(true) - $started) / 1_000_000));
        self::assertCount(300, $assessments);
        self::assertLessThanOrEqual(20, $previewQueries);
        self::assertLessThanOrEqual(30, $adminQueries);
        self::assertLessThanOrEqual(40, $searchQueries);
        self::assertLessThanOrEqual(40, $filterQueries);
    }
}
