<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Employee;
use App\Services\BidAssignmentReceiver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class BidAssignmentsE2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite') {
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
    }
}
