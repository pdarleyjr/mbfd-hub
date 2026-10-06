<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use App\Models\Employee;
use App\Models\User;
use App\Services\BidAssignmentReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EmployeeBidAssignmentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('selections')]
    public function test_dashboard_preserves_exact_selection_seat_and_day(string $selection, string $seat, string $code, string $day): void
    {
        $employee = $this->linkedEmployee('TEST-BID-VIEW');
        $this->assignment($employee, $selection, $seat, $code, $day);
        $this->actingAs($employee, 'employee')->get('/employee/dashboard')->assertOk()
            ->assertSee('2026–2027 Bid Selection')->assertSee($selection)->assertSee($seat)
            ->assertSee('Rank at bid')->assertSee('Lieutenant')->assertSee('A Shift')->assertSee('Station #1')
            ->assertSee($day)->assertSee('Bid award')
            ->assertDontSee('Open Bid Console')->assertDontSee('Bid Certifications')
            ->assertDontSee('TEST-REAL-VIEW')->assertDontSee('view-key-TEST-BID-VIEW');
        self::assertFalse(Route::has('filament.employee.pages.my-bid-certifications'));
    }

    public static function selections(): array
    {
        return [
            ['Captain 5', 'Captain', 'G4', 'Group 4'],
            ['Combat Float', 'Firefighter DE #2', 'G3', 'Group 3'],
            ['Rescue Float', 'Lieutenant Rescue Float', 'MON', 'Monday'],
            ['Combat 1', 'Fire Investigator', 'FRI', 'Friday'],
        ];
    }

    public function test_retained_member_sees_assignment_wording_without_claiming_a_bid_award(): void
    {
        $employee = $this->linkedEmployee('TEST-BID-VIEW');
        $this->assignment($employee, 'Prevention', 'Division Chief', 'MON', 'Monday', true);
        $this->actingAs($employee, 'employee')->get('/employee/dashboard')->assertOk()
            ->assertSee('2026–2027 Assignment')->assertSee('Retained assignment')
            ->assertSee('Prevention')->assertSee('Division Chief')->assertSee('Monday')
            ->assertDontSee('2026–2027 Bid Selection')->assertDontSee('Bid award');
    }

    public function test_canonical_employee_can_only_see_their_own_current_record(): void
    {
        $first = $this->linkedEmployee('TEST-BID-FIRST');
        $other = $this->linkedEmployee('TEST-BID-OTHER');
        $this->assignment($first, 'Captain 5', 'Captain', 'G4', 'Group 4');
        $this->assignment($other, 'Marine Float', 'Deckhand', 'G1', 'Group 1');
        $this->actingAs($first, 'employee')->get('/employee/dashboard?employee_id=TEST-BID-OTHER')
            ->assertOk()->assertSee('Captain 5')->assertDontSee('Marine Float')->assertDontSee('Deckhand');
    }

    public function test_guest_has_no_employee_dashboard_access(): void
    {
        $employee = $this->linkedEmployee('TEST-BID-VIEW');
        $this->assignment($employee, 'Captain 5', 'Captain', 'G4', 'Group 4');
        $this->get('/employee/dashboard')->assertRedirect()->assertDontSee('Captain 5');
    }

    private function linkedEmployee(string $id): Employee
    {
        $employee = Employee::query()->create(['employee_id' => $id, 'name' => 'Synthetic Dashboard Fixture',
            'rank' => 'Firefighter', 'must_change_password' => false]);
        User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);

        return $employee;
    }

    private function assignment(Employee $employee, string $selection, string $seat, string $code, string $day, bool $retained = false): void
    {
        app(BidAssignmentReceiver::class)->receive($employee->employee_id, [
            'payload_version' => 2, 'bid_year' => 2026, 'term_label' => '2026–2027', 'bid_session_id' => 'TEST-REAL-VIEW',
            'employee_id' => $employee->employee_id, 'rank_label' => 'Lieutenant', 'shift_label' => 'A Shift',
            'station_label' => 'Station #1', 'division_label' => 'Operations', 'unit_label' => $selection,
            'position_id' => 'A101', 'position_label' => $seat, 'bid_selection_label' => $selection,
            'assignment_type' => 'Assigned', 'assignment_source' => $retained ? 'retained_nonbiddable' : 'bid_award',
            'a_day_code' => $code, 'a_day_label' => $day, 'picked_at' => $retained ? null : '2026-10-05T12:00:00Z',
            'idempotency_key' => 'view-key-'.$employee->employee_id, 'is_forced' => false, 'admin_actor_employee_id' => null,
            'source_sequence' => 3, 'source_result_hash' => str_repeat('a', 64), 'source_workbook_sha256' => str_repeat('b', 64),
        ]);
    }
}
