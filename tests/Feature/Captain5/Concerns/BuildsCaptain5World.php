<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5\Concerns;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\Captain5\Captain5DutyAssignment;
use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\Station;
use App\Models\User;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\Captain5\Captain5IssueScope;
use App\Services\Captain5\MedicalScope;
use App\Services\Security\Captain5Access;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Inspections\Concerns\BuildsDailyScenarioWorld;

/**
 * Captain 5 test world built on the daily-inspection scenario world: Engine 2 ($this->unit),
 * Engine 3 ($this->frontline), plus Rescue 2 ($this->rescue) and Ladder 3 ($this->ladder3).
 * Findings are recorded through the real Daily Checkout HTTP endpoint.
 *
 * The clock is pinned to a known A-shift duty day (2026-03-09 is the rotation anchor).
 */
trait BuildsCaptain5World
{
    use BuildsDailyScenarioWorld;

    protected Apparatus $rescue;

    protected Apparatus $ladder3;

    private int $c5Sequence = 0;

    protected function buildCaptain5World(string $now = '2026-10-20 10:00:00'): void
    {
        // 2026-10-20 is 225 days after the A anchor: 225 % 3 = 0, so an A-shift duty day.
        $this->travelTo(CarbonImmutable::parse($now, 'America/New_York'));
        $this->buildWorld();
        Permission::findOrCreate(Captain5Access::PERMISSION_ADMIN, 'web');

        $station2 = Station::query()->where('station_number', 2)->firstOrFail();
        $station3 = Station::query()->where('station_number', 3)->firstOrFail();
        $this->rescue = Apparatus::query()->create([
            'station_id' => $station2->id, 'vin' => 'VIN-R2', 'assignment' => 'Station 2',
            'unit_id' => 'R2', 'designation' => 'R2', 'name' => 'Rescue 2', 'type' => 'Rescue',
            'vehicle_number' => 'V-R2', 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'rescue',
            'current_engine_hours' => 300, 'current_miles' => 2000,
        ]);
        $this->ladder3 = Apparatus::query()->create([
            'station_id' => $station3->id, 'vin' => 'VIN-L3', 'assignment' => 'Station 3',
            'unit_id' => 'L3', 'designation' => 'L3', 'name' => 'Ladder 3', 'type' => 'Ladder',
            'vehicle_number' => 'V-L3', 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'ladder3',
            'current_engine_hours' => 700, 'current_miles' => 6000,
        ]);
        $this->forgetCaptain5Memo();
    }

    /** A member with a personnel profile of the given rank. */
    protected function officer(string $rank, string $name = ''): User
    {
        $this->c5Sequence++;
        $employee = Employee::query()->create([
            'employee_id' => 'C5-'.$this->c5Sequence.'-'.random_int(1000, 9999),
            'name' => $name !== '' ? $name : $rank.' '.$this->c5Sequence,
            'rank' => $rank, 'password' => 'not-used-by-tests', 'must_change_password' => false,
        ]);

        return User::factory()->create([
            'account_status' => AccountStatus::Active, 'must_change_password' => false,
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
            'name' => $employee->name,
        ]);
    }

    /** A verified current bid seat (`Captain 5` / `EMS` / `300` / `Rescue 2` ...). */
    protected function seat(User $user, string $unit, string $shift = 'A Shift'): EmployeeBidAssignment
    {
        $this->c5Sequence++;
        $row = EmployeeBidAssignment::query()->create([
            'employee_profile_id' => $user->employee_profile_id, 'payload_version' => 2, 'bid_year' => 2026,
            'term_label' => '2026–2027', 'bid_session_id' => 'bid-2026', 'position_id' => 'C5P'.$this->c5Sequence,
            'rank_label' => 'Captain', 'shift_label' => $shift, 'station_label' => 'Station 2',
            'unit_label' => $unit, 'position_label' => $unit, 'bid_selection_label' => $unit,
            'assignment_type' => 'Assigned', 'assignment_source' => 'bid_award', 'a_day_label' => 'A-Day',
            'idempotency_key' => 'c5-key-'.$this->c5Sequence, 'payload_hash' => str_repeat('a', 64), 'is_forced' => false,
            'source_sequence' => $this->c5Sequence, 'source_result_hash' => str_repeat('b', 64), 'source_workbook_sha256' => str_repeat('c', 64),
        ]);
        $this->forgetCaptain5Memo();

        return $row;
    }

    protected function superAdmin(): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** Admin panel user with Captain 5 configuration rights (no Fleet capability). */
    protected function captain5Administrator(): User
    {
        $user = $this->officer('Firefighter', 'Captain 5 Admin');
        $user->givePermissionTo(array_map(static fn (string $p): Permission => Permission::findOrCreate($p, 'web'), ['admin.access', Captain5Access::PERMISSION_ADMIN]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** An acting Captain 5 for the current duty day, written directly (assignment API has its own tests). */
    protected function actingAssignment(User $member, User $assignedBy, ?string $dutyDate = null): Captain5DutyAssignment
    {
        $calendar = app(DutyCalendar::class);
        $dutyDate ??= $calendar->dutyDate()->format('Y-m-d');
        [$start, $end] = $calendar->window($dutyDate);
        $assignment = Captain5DutyAssignment::query()->create([
            'employee_profile_id' => $member->employee_profile_id, 'duty_date' => $dutyDate,
            'starts_at' => $start, 'ends_at' => $end, 'shift_letter' => $calendar->shiftFor($dutyDate),
            'reason' => 'Out of class coverage', 'assigned_by_user_id' => $assignedBy->getKey(),
        ]);
        $this->forgetCaptain5Memo();

        return $assignment;
    }

    /**
     * Submit a Daily Checkout of $apparatus as $member, reporting the named item ids Missing.
     *
     * @param  list<string>  $missingItemIds
     * @return list<ApparatusDefect> the open defects for those items afterwards
     */
    protected function reportMissing(Apparatus $apparatus, User $member, array $missingItemIds, array $extra = []): array
    {
        $this->actAs($member);
        $payload = $this->payload($apparatus, (int) $apparatus->fresh()->current_miles + 5, (float) $apparatus->fresh()->current_engine_hours + 0.5);
        $found = [];
        foreach ($payload['compartments'] as $ci => $compartment) {
            foreach ($compartment['items'] as $ii => $item) {
                if (in_array($item['id'], $missingItemIds, true)) {
                    $this->markItem($payload, $ci, $ii, 'Missing', ['notes' => 'Not on the unit', ...$extra]);
                    $found[] = $item['id'];
                }
            }
        }
        $this->assertEqualsCanonicalizing($missingItemIds, $found, 'Every requested item must exist on the checklist.');
        $this->submit($payload, $apparatus)->assertSuccessful();
        $this->forgetCaptain5Memo();

        return ApparatusDefect::query()->where('apparatus_id', $apparatus->id)->whereIn('item_key', $missingItemIds)
            ->where('resolved', false)->orderBy('id')->get()->all();
    }

    /** Submit a Daily Checkout reporting every item Present. */
    protected function reportAllPresent(Apparatus $apparatus, User $member): void
    {
        $this->actAs($member);
        $payload = $this->payload($apparatus, (int) $apparatus->fresh()->current_miles + 5, (float) $apparatus->fresh()->current_engine_hours + 0.5);
        $this->submit($payload, $apparatus)->assertSuccessful();
        $this->forgetCaptain5Memo();
    }

    /** First item id of the first compartment of a unit's checklist (e.g. a Rescue item). */
    protected function firstItemId(Apparatus $apparatus, int $compartment = 0, int $item = 0): string
    {
        return (string) $this->payload($apparatus)['compartments'][$compartment]['items'][$item]['id'];
    }

    protected function forgetCaptain5Memo(): void
    {
        app()->forgetScopedInstances();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([Captain5Access::class, Captain5DutyRoster::class, Captain5IssueScope::class, MedicalScope::class] as $service) {
            if (app()->resolved($service)) {
                app($service)->flush();
            }
        }
    }
}
