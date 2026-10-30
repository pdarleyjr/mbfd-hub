<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Synthetic personas for the Narcotics check browser suite (docs/narcotics-check.md). Testing database only.
 *
 *   99401 Pat Member     member (sends checks)
 *   99402 Olive Other    member (must never see Pat's photo)
 *   99403 Cap Five       Captain 5 bid seat holder, A Shift (reviewer, and a sender whose own check he cannot decide)
 *   99404 Dana Second    Captain 5 bid seat holder, B Shift (second reviewer)
 *
 * Every persona has an active personnel profile, so each signs in through the Employee ID form members use.
 * One random password per run comes from the environment and is never written down.
 */
final class NarcoticsChecksE2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || basename((string) config('database.connections.sqlite.database')) !== 'narcotics_checks_e2e.sqlite') {
            throw new RuntimeException('Narcotics check browser fixtures require their disposable testing database.');
        }
        $password = env('NARCOTICS_CHECK_E2E_PASSWORD');
        if (! is_string($password) || strlen($password) < 16) {
            throw new RuntimeException('The browser test password must be supplied.');
        }

        $memberRole = Role::findOrCreate('member', 'web');
        $personas = [
            ['99401', 'Pat Member', 'Firefighter', null],
            ['99402', 'Olive Other', 'Firefighter', null],
            ['99403', 'Cap Five', 'Captain', 'A Shift'],
            ['99404', 'Dana Second', 'Captain', 'B Shift'],
        ];

        foreach ($personas as $index => [$number, $name, $rank, $seatShift]) {
            $employee = Employee::query()->updateOrCreate(
                ['employee_id' => $number],
                ['name' => $name, 'rank' => $rank, 'city_email' => "narcotics-fixture-{$number}@miamibeachfl.gov", 'roster_status' => 'active'],
            );
            $user = User::query()->firstOrNew(['employee_id' => $number]);
            $user->forceFill([
                'name' => $name,
                'email' => $employee->city_email,
                'password' => Hash::make($password),
                'must_change_password' => false,
                'employee_id' => $number,
                'employee_profile_id' => $employee->id,
                'account_status' => 'active',
            ])->save();
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$memberRole]);

            if ($seatShift !== null) {
                $this->captain5Seat($employee, $seatShift, $index + 1);
            }
        }
    }

    /** A verified Captain 5 bid seat (the same row shape the EMS test world builds). */
    private function captain5Seat(Employee $employee, string $shift, int $sequence): void
    {
        EmployeeBidAssignment::query()->create([
            'employee_profile_id' => $employee->id, 'payload_version' => 2, 'bid_year' => 2026,
            'term_label' => "2026\u{2013}2027", 'bid_session_id' => 'bid-2026', 'position_id' => 'NCP'.$sequence,
            'rank_label' => 'Captain', 'shift_label' => $shift, 'station_label' => 'Station 2',
            'unit_label' => 'Captain 5', 'position_label' => 'Captain 5', 'bid_selection_label' => 'Captain 5',
            'assignment_type' => 'Assigned', 'assignment_source' => 'bid_award', 'a_day_label' => 'A-Day',
            'idempotency_key' => 'narcotics-e2e-key-'.$sequence, 'payload_hash' => str_repeat('a', 64), 'is_forced' => false,
            'source_sequence' => $sequence, 'source_result_hash' => str_repeat('b', 64), 'source_workbook_sha256' => str_repeat('c', 64),
        ]);
    }
}
