<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource\Pages\ListPersonnelRequests;
use App\Filament\Employee\Pages\RequestEquipmentPage;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\User;
use App\Services\BidAssignmentReceiver;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use App\Services\PersonnelRequests\UniformAllowanceService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Rescue profile: 4 t-shirts per allowance year. */
final class UniformAnnualAllowanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    }

    public function test_within_allowance_submits_unchanged_and_is_not_flagged(): void
    {
        $employee = $this->rescueMember('ALLOW-WITHIN');

        $request = $this->submit($employee, 4, 'allow-within-1');

        $this->assertFalse($request->fresh()->over_allowance);
        $this->assertNull($request->allowance_justification);
        $this->assertArrayNotHasKey('allowance_at_submission', $request->metadata);
    }

    public function test_over_allowance_without_reason_is_rejected_with_a_friendly_message(): void
    {
        $employee = $this->rescueMember('ALLOW-NOREASON');

        try {
            $this->submit($employee, 5, 'allow-noreason-1');
            $this->fail('An over-allowance request without a reason was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('over your remaining annual allowance', $exception->errors()['allowance_justification'][0]);
        }
        $this->assertDatabaseCount('personnel_requests', 0);
    }

    public function test_over_allowance_with_reason_is_accepted_and_flagged(): void
    {
        $employee = $this->rescueMember('ALLOW-REASON');

        $request = $this->submit($employee, 6, 'allow-reason-1', '  Shirts were damaged at a fire.  ');

        $this->assertSame(PersonnelRequestStatus::Pending, $request->status);
        $this->assertTrue($request->fresh()->over_allowance);
        $this->assertSame('Shirts were damaged at a fire.', $request->allowance_justification);
        $this->assertSame('2026', $request->metadata['allowance_at_submission']['period']);
        $this->assertSame(['T-shirts: 6 selected, 4 of 4 remaining this year.'], $request->metadata['allowance_at_submission']['over']);
    }

    public function test_overlong_reason_is_rejected(): void
    {
        $employee = $this->rescueMember('ALLOW-LONG');

        $this->expectException(ValidationException::class);
        $this->submit($employee, 6, 'allow-long-1', str_repeat('a', (int) config('uniform_orders.allowance_justification_max') + 1));
    }

    public function test_earlier_requests_this_year_count_toward_the_allowance(): void
    {
        $employee = $this->rescueMember('ALLOW-YTD');
        $this->submit($employee, 3, 'allow-ytd-1');

        try {
            $this->submit($employee, 2, 'allow-ytd-2');
            $this->fail('Year-to-date usage was not counted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allowance_justification', $exception->errors());
        }
        $this->assertFalse($this->submit($employee, 1, 'allow-ytd-3')->fresh()->over_allowance);
    }

    public function test_cancelled_denied_and_previous_year_requests_do_not_count(): void
    {
        $employee = $this->rescueMember('ALLOW-EXCLUDED');
        $this->submit($employee, 4, 'allow-excluded-cancelled')->update(['status' => PersonnelRequestStatus::Cancelled]);
        $this->submit($employee, 4, 'allow-excluded-denied')->update(['status' => PersonnelRequestStatus::Denied]);
        $this->submit($employee, 4, 'allow-excluded-old')->update(['created_at' => '2025-12-31 23:00:00']);

        $this->assertSame([], app(UniformAllowanceService::class)->usedByGroup($employee));
        $this->assertFalse($this->submit($employee, 4, 'allow-excluded-new')->fresh()->over_allowance);
    }

    public function test_allowance_year_start_is_configurable(): void
    {
        config()->set('uniform_orders.allowance_year', ['start_month' => 7, 'start_day' => 1]);
        $period = app(UniformAllowanceService::class)->period(CarbonImmutable::parse('2026-03-10'));

        $this->assertSame('2025-07-01', $period['start']->toDateString());
        $this->assertSame('2026-07-01', $period['end']->toDateString());
    }

    public function test_builder_shows_remaining_and_requires_a_reason_only_when_over(): void
    {
        $this->withoutVite();
        $employee = $this->rescueMember('ALLOW-PAGE');
        $this->submit($employee, 3, 'allow-page-prior');
        $this->actingAs($employee, 'employee');
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        $page = Livewire::test(RequestEquipmentPage::class)
            ->assertSee('data-allowance-remaining', false)
            ->assertSee('1 of 4 t-shirts remaining this year')
            ->assertDontSee('data-over-allowance', false)
            ->set('data.items.t_shirt.quantity', 2)
            ->set('data.items.t_shirt.metadata.size', 'L')
            ->assertSee('data-over-allowance', false)
            ->assertSee('Reason for the extra items')
            ->call('submit')
            ->assertHasErrors(['data.allowance_justification']);
        $this->assertDatabaseCount('personnel_requests', 1);

        $page->set('data.allowance_justification', 'New assignment needs extra shirts.')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('data.allowance_justification', '');
        $flagged = PersonnelRequest::query()->where('over_allowance', true)->sole();
        $this->assertSame('New assignment needs extra shirts.', $flagged->allowance_justification);
        // The usage cache is refreshed after a submission.
        $page->assertSee('0 of 4 t-shirts remaining this year');
    }

    public function test_admin_sees_the_flag_reason_and_can_filter_flagged_requests(): void
    {
        $this->withoutVite();
        $employee = $this->rescueMember('ALLOW-ADMIN');
        $within = $this->submit($employee, 1, 'allow-admin-within');
        $flagged = $this->submit($employee, 6, 'allow-admin-over', 'Lost in a flood.');
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/personnel-uniforms-equipment/personnel-requests/'.$flagged->public_id)
            ->assertOk()->assertSee('Over annual allowance')->assertSee('Lost in a flood.');
        $this->actingAs($admin)->get('/admin/personnel-uniforms-equipment/personnel-requests/'.$within->public_id)
            ->assertOk()->assertDontSee('Lost in a flood.');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListPersonnelRequests::class)
            ->assertCanSeeTableRecords([$within, $flagged])
            ->filterTable('over_allowance')
            ->assertCanSeeTableRecords([$flagged])
            ->assertCanNotSeeTableRecords([$within]);
    }

    private function submit(Employee $employee, int $tShirts, string $key, ?string $reason = null): PersonnelRequest
    {
        return app(PersonnelRequestSubmissionService::class)->submitUniform(
            $employee,
            [['item_code' => 't_shirt', 'metadata' => ['size' => 'L'], 'quantity' => $tShirts]],
            $key,
            ['allowance_justification' => $reason],
        );
    }

    private function rescueMember(string $number): Employee
    {
        $employee = Employee::query()->create(['employee_id' => $number, 'name' => 'Allowance Member '.$number,
            'rank' => 'Firefighter', 'password' => 'unused-fixture-credential', 'must_change_password' => false]);
        User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);
        app(BidAssignmentReceiver::class)->receive($employee->employee_id, [
            'payload_version' => 2, 'bid_year' => 2027, 'term_label' => '2026–2027',
            'bid_session_id' => 'ALLOWANCE-TEST', 'employee_id' => $employee->employee_id,
            'rank_label' => 'Firefighter', 'shift_label' => 'A Shift', 'station_label' => 'Station 1',
            'division_label' => 'Operations', 'unit_label' => 'Rescue 11', 'position_id' => 'A101',
            'position_label' => 'Firefighter #2', 'bid_selection_label' => 'Rescue 11',
            'assignment_type' => 'Assigned', 'assignment_source' => 'bid_award',
            'a_day_code' => 'G1', 'a_day_label' => 'Group 1', 'picked_at' => '2026-10-05T12:00:00Z',
            'idempotency_key' => 'allowance-bid-'.$employee->employee_id,
            'is_forced' => false, 'admin_actor_employee_id' => null, 'source_sequence' => 3,
            'source_result_hash' => str_repeat('a', 64), 'source_workbook_sha256' => str_repeat('b', 64),
        ]);

        return $employee;
    }

    private function admin(): User
    {
        Role::findOrCreate('logistics_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('logistics_admin');
        $admin->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.personnel.view', 'web'),
            Permission::findOrCreate('admin.personnel.manage', 'web'),
        ]);

        return $admin;
    }
}
