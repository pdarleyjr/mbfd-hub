<?php

declare(strict_types=1);

namespace Tests\Feature\Defects\Concerns;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusDefectParticipant;
use App\Models\ApparatusInspection;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Synthetic rig, members, reviewers, inspections and issues for the Agent E suites. */
trait BuildsContinuityFixtures
{
    private int $fixtureCounter = 0;

    protected function member(string $name = 'Pat Member', bool $active = true): User
    {
        return $this->userFor($this->employee($name), $active);
    }

    protected function employee(string $name = 'Pat Member'): Employee
    {
        $this->fixtureCounter++;

        return Employee::query()->create([
            'employee_id' => 'E-'.$this->fixtureCounter.'-'.random_int(1000, 9999),
            'name' => $name,
            'rank' => 'Firefighter',
            'password' => 'not-used-by-tests',
            'must_change_password' => false,
        ]);
    }

    protected function userFor(Employee $employee, bool $active = true, ?string $lastLogin = null): User
    {
        $user = User::factory()->create([
            'account_status' => $active ? AccountStatus::Active : AccountStatus::Disabled,
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'name' => $employee->name,
        ]);
        if ($lastLogin !== null) {
            $user->forceFill(['last_login_at' => $lastLogin])->save();
        }

        return $user;
    }

    protected function reviewer(string $role = 'logistics_admin', ?string $name = null): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'name' => $name ?? 'Reviewer '.++$this->fixtureCounter,
        ]);
        $user->assignRole(Role::findOrCreate($role, 'web'));

        return $user;
    }

    protected function allowApparatusUpdate(User $user): void
    {
        $user->givePermissionTo(Permission::findOrCreate('update_apparatus', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function rig(string $name = 'Engine 2', string $designation = 'E2'): Apparatus
    {
        $this->fixtureCounter++;
        $station = Station::query()->create([
            'station_number' => 100 + $this->fixtureCounter,
            'name' => 'Station '.$this->fixtureCounter,
            'address' => '1 Test Way',
            'is_active' => true,
        ]);

        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => $designation.'-'.$this->fixtureCounter,
            'name' => $name,
            'type' => 'Engine',
            'vehicle_number' => 'V-'.$this->fixtureCounter,
            'designation' => $designation,
            'slug' => strtolower($designation).'-'.$this->fixtureCounter,
            'status' => 'In Service',
            'current_engine_hours' => 100.0,
            'current_miles' => 1000,
        ]);
    }

    protected function inspectionBy(Apparatus $rig, ?User $user, string $at = '2026-10-19 09:00:00'): ApparatusInspection
    {
        $this->fixtureCounter++;

        return ApparatusInspection::query()->create([
            'apparatus_id' => $rig->id,
            'processed_apparatus_id' => $rig->id,
            'actor_user_id' => $user?->id,
            'employee_id' => $user?->employee_profile_id,
            'operator_name' => $user?->name ?? 'Unknown',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => $rig->designation,
            'designation_at_time' => $rig->designation,
            'inspection_reference' => 'INS-T-'.$this->fixtureCounter,
            'review_status' => 'approved',
            'processing_status' => 'accepted',
            'results' => [],
            'completed_at' => $at,
        ]);
    }

    /** An open "missing" issue first reported in $inspection by its owner, who is enrolled as `reported`. */
    protected function openDefect(Apparatus $rig, ApparatusInspection $inspection, ?User $reporter, string $item = 'Portable radio battery'): ApparatusDefect
    {
        $defect = ApparatusDefect::query()->create([
            'apparatus_id' => $rig->id,
            'apparatus_inspection_id' => $inspection->id,
            'compartment' => 'Cab',
            'item' => $item,
            'compartment_key' => 'cab',
            'item_key' => 'cab-'.Str::slug($item),
            'status' => 'open',
            'issue_type' => 'missing',
            'reported_date' => '2026-10-19',
            'first_reported_inspection_id' => $inspection->id,
            'first_reported_by_user_id' => $reporter?->id,
            'first_reported_at' => $inspection->completed_at,
            'last_observed_inspection_id' => $inspection->id,
            'last_observed_at' => $inspection->completed_at,
        ]);
        $this->observe($defect, $inspection, 'first_reported', $reporter);
        if ($reporter !== null) {
            $this->participant($defect, $reporter, 'reported', $inspection);
        }

        return $defect->refresh();
    }

    protected function observe(ApparatusDefect $defect, ApparatusInspection $inspection, string $observation, ?User $actor): void
    {
        ApparatusDefectObservation::query()->create([
            'apparatus_defect_id' => $defect->id,
            'apparatus_inspection_id' => $inspection->id,
            'actor_user_id' => $actor?->id,
            'observation' => $observation,
            'reported_status' => $observation === 'appears_corrected' ? 'Present' : 'Missing',
        ]);
    }

    protected function participant(ApparatusDefect $defect, User $user, string $source = 'confirmed', ?ApparatusInspection $inspection = null): void
    {
        ApparatusDefectParticipant::query()->create([
            'apparatus_defect_id' => $defect->id,
            'user_id' => $user->id,
            'employee_id' => $user->employee_profile_id,
            'enrollment_source' => $source,
            'first_inspection_id' => $inspection?->id,
            'last_inspection_id' => $inspection?->id,
            'first_participated_at' => now(),
            'last_participated_at' => now(),
        ]);
    }
}
