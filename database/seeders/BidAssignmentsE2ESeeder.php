<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use App\Services\BidAssignmentReceiver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

final class BidAssignmentsE2ESeeder extends Seeder
{
    public function run(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite'
            || ! is_file($database) || realpath(dirname($database)) !== realpath(database_path())
            || basename($database) === 'database.sqlite') {
            throw new \RuntimeException('Bid browser fixtures require the disposable SQLite testing application.');
        }

        foreach (['99002' => false, '99001' => true] as $employeeId => $retained) {
            $employee = Employee::query()->where('employee_id', (string) $employeeId)->sole();
            app(BidAssignmentReceiver::class)->receive($employee->employee_id, [
                'payload_version' => 2, 'bid_year' => 2026, 'term_label' => '2026–2027',
                'bid_session_id' => 'TEST-BROWSER-REAL', 'employee_id' => $employee->employee_id,
                'rank_label' => $retained ? 'Division Chief' : 'Firefighter', 'shift_label' => $retained ? 'D Shift' : 'A Shift',
                'station_label' => $retained ? 'Administration' : 'Station #1', 'division_label' => 'Operations',
                'unit_label' => $retained ? 'Prevention' : 'Combat Float', 'position_id' => $retained ? 'D201' : 'A101',
                'position_label' => $retained ? 'Division Chief' : 'Firefighter DE #2',
                'bid_selection_label' => $retained ? 'Prevention' : 'Combat Float',
                'assignment_type' => $retained ? 'Assigned' : 'Floating',
                'assignment_source' => $retained ? 'retained_nonbiddable' : 'bid_award',
                'a_day_code' => $retained ? 'MON' : 'G3', 'a_day_label' => $retained ? 'Monday' : 'Group 3',
                'picked_at' => $retained ? null : '2026-10-05T12:00:00Z',
                'idempotency_key' => 'browser-bid-'.$employeeId, 'is_forced' => false, 'admin_actor_employee_id' => null,
                'source_sequence' => 3, 'source_result_hash' => str_repeat('a', 64), 'source_workbook_sha256' => str_repeat('b', 64),
            ]);
        }

        $password = getenv('PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD');
        if (! is_string($password) || $password === '') {
            throw new \RuntimeException('The isolated canonical member fixture password is required.');
        }
        $passwordHash = Hash::make($password);
        $unusableEmployeePassword = Hash::make(bin2hex(random_bytes(32)));
        $memberRole = Role::findOrCreate('member', 'web');

        // Each responsive project has its own canonical fixture identities.
        // Every card is reached by normal login; no impersonation/session seam.
        foreach (['P', 'T', 'D', 'W'] as $project) {
            foreach ($this->scenarios() as $index => $scenario) {
                [$selection, $rank, $seat, $shift, $area, $division, $type, $dayCode, $dayLabel, $retained] = $scenario;
                $employeeId = 'BID-E2E-'.$project.sprintf('%02d', $index + 1);
                $email = strtolower($employeeId).'-fixture@miamibeachfl.gov';
                $employee = Employee::query()->create([
                    'employee_id' => $employeeId,
                    'name' => 'Synthetic Bid '.$employeeId,
                    'rank' => $rank,
                    'city_email' => $email,
                    'roster_status' => 'active',
                    'password' => $unusableEmployeePassword,
                    'must_change_password' => false,
                ]);
                $user = User::factory()->create([
                    'employee_id' => $employeeId,
                    'employee_profile_id' => $employee->id,
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => $passwordHash,
                    'must_change_password' => false,
                    'account_status' => 'active',
                    'is_admin' => false,
                ]);
                $user->syncRoles([$memberRole]);

                $payload = [
                    'payload_version' => 2, 'bid_year' => 2026, 'term_label' => '2026–2027',
                    'bid_session_id' => 'TEST-BROWSER-REAL-'.$project,
                    'employee_id' => $employeeId, 'rank_label' => $rank, 'shift_label' => $shift,
                    'station_label' => $area, 'division_label' => $division, 'unit_label' => $selection,
                    'position_id' => 'Z'.(901 + $index), 'position_label' => $seat,
                    'bid_selection_label' => $selection, 'assignment_type' => $type,
                    'assignment_source' => $retained ? 'retained_nonbiddable' : 'bid_award',
                    'a_day_code' => $dayCode, 'a_day_label' => $dayLabel,
                    'picked_at' => $retained ? null : '2026-10-05T12:00:00Z',
                    'idempotency_key' => 'browser-'.$employeeId, 'is_forced' => false,
                    'admin_actor_employee_id' => null, 'source_sequence' => 3,
                    'source_result_hash' => str_repeat('a', 64), 'source_workbook_sha256' => str_repeat('b', 64),
                ];
                if ($seat === 'Air Tech') {
                    app(BidAssignmentReceiver::class)->receive($employeeId, array_replace($payload, [
                        'idempotency_key' => 'browser-'.$employeeId.'-prior',
                        'position_label' => 'Air Tech (superseded fixture revision)',
                    ]));
                    $payload['source_sequence'] = 4;
                }
                app(BidAssignmentReceiver::class)->receive($employeeId, $payload);
            }
        }
    }

    /** @return list<array{string, string, string, string, string, string, string, string, string, bool}> */
    private function scenarios(): array
    {
        return [
            ['Captain 5', 'Captain', 'Captain', 'B Shift', 'Station #2', 'Operations', 'Assigned', 'G4', 'Group 4', false],
            ['Rescue Float', 'Lieutenant', 'Lieutenant Rescue Float', 'B Shift', 'Float', 'Rescue', 'Floating', 'G2', 'Group 2', false],
            ['Rescue Float', 'Firefighter', 'Firefighter', 'C Shift', 'Float', 'Rescue', 'Floating', 'G1', 'Group 1', false],
            ['Combat Float', 'Firefighter', 'Firefighter', 'A Shift', 'Float', 'Combat', 'Floating', 'G3', 'Group 3', false],
            ['Engine 2', 'Firefighter', 'Firefighter DE #1', 'B Shift', 'Station #2', 'Combat', 'Assigned', 'G4', 'Group 4', false],
            ['Engine 4', 'Firefighter', 'Firefighter #2', 'C Shift', 'Station #4', 'Combat', 'Assigned', 'G2', 'Group 2', false],
            ['Rescue 1', 'Firefighter', 'Firefighter #2', 'A Shift', 'Station #1', 'Rescue', 'Assigned', 'G1', 'Group 1', false],
            ['Fire Boat 6', 'Firefighter', 'Fire Boat Operator', 'B Shift', 'Station #6', 'Marine', 'Assigned', 'G3', 'Group 3', false],
            ['Fire Boat 6', 'Firefighter', 'Fire Boat Engineer', 'C Shift', 'Station #6', 'Marine', 'Assigned', 'G2', 'Group 2', false],
            ['Fire Boat 6', 'Firefighter', 'Deckhand', 'A Shift', 'Station #6', 'Marine', 'Assigned', 'G4', 'Group 4', false],
            ['Marine Float', 'Firefighter', 'Marine FBO', 'C Shift', 'Float', 'Marine', 'Floating', 'G1', 'Group 1', false],
            ['Special Events', 'Captain', 'Captain', 'D Shift', 'Special Events', 'Support Services', 'Assigned', 'MON', 'Monday', false],
            ['Public Education', 'Firefighter', 'Firefighter', 'D Shift', 'Public Education', 'Prevention', 'Assigned', 'FRI', 'Friday', false],
            ['Union President', 'Firefighter', 'Union President', 'A Shift', 'Union Office', 'Administration', 'Assigned', 'G4', 'Group 4', true],
            ['Combat 1', 'Firefighter', 'Firefighter DE #1', 'A Shift', 'Station #1', 'Combat', 'Assigned', 'G1', 'Group 1', false],
            ['Combat 3', 'Firefighter', 'Firefighter #2', 'C Shift', 'Station #3', 'Combat', 'Assigned', 'G2', 'Group 2', false],
            ['Combat Float', 'Firefighter', 'Air Tech', 'A Shift', 'Float', 'Support Services', 'Floating', 'G2', 'Group 2', false],
            ['Combat 1', 'Firefighter', 'Fire Investigator', 'B Shift', 'Station #1', 'Combat', 'Assigned', 'G4', 'Group 4', false],
        ];
    }
}
