<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Filament\Clusters\PersonnelUniformsEquipment\Pages\PersonnelOverview;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelAssignmentResource\Pages\EditPersonnelAssignment;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelAssignmentResource\Pages\ListPersonnelAssignments;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelEmployeeResource\Pages\ViewPersonnelEmployee;
use App\Filament\Employee\Pages\MyEquipmentPage;
use App\Filament\Resources\UniformResource\Pages\ListUniforms;
use App\Models\AssignedEquipment;
use App\Models\Employee;
use App\Models\EquipmentExpirationNotification;
use App\Models\PersonnelRequest;
use App\Models\Uniform;
use App\Models\User;
use App\Services\PersonnelRequests\UniformAllowanceService;
use App\Services\PersonnelRequests\UniformEntitlementService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EasternPersonnelCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_member_expiry_labels_and_overview_wait_for_eastern_midnight(): void
    {
        $employee = $this->employee();
        $today = $this->equipment($employee, '2026-10-26');
        $tomorrow = $this->equipment($employee, '2026-10-27');
        $originalDate = $today->getRawOriginal('expires_at');
        $this->freeze('2026-10-27T00:01:00Z');
        $this->actingAs($employee, 'employee');
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $page = Livewire::test(MyEquipmentPage::class)
            ->assertSee('Expiring Soon · Oct 26, 2026')->assertDontSee('Expired · Oct 26, 2026');
        self::assertCount(0, $page->instance()->getViewData()['expired']);
        self::assertSame(0, (new PersonnelOverview)->getViewData()['expired']);

        $this->freeze('2026-10-27T04:00:00Z');
        $this->actingAs($employee, 'employee');
        $this->bindCanonicalSessionToLivewireTestRequests();
        $page = Livewire::test(MyEquipmentPage::class)->assertSee('Expired · Oct 26, 2026')
            ->assertSee('Expiring Soon · Oct 27, 2026');
        self::assertSame([$today->id], $page->instance()->getViewData()['expired']->modelKeys());
        self::assertSame(1, (new PersonnelOverview)->getViewData()['expired']);
        self::assertSame($originalDate, $today->fresh()->getRawOriginal('expires_at'));
        self::assertSame('2026-10-27', $tomorrow->fresh()->expires_at->toDateString());
    }

    public function test_assignment_filters_use_the_same_eastern_calendar_day(): void
    {
        $employee = $this->employee();
        $today = $this->equipment($employee, '2026-10-26');
        $tomorrow = $this->equipment($employee, '2026-10-27');
        $this->freeze('2026-10-27T00:01:00Z');
        $this->admin();
        Livewire::test(ListPersonnelAssignments::class)->filterTable('expiration', 'expired')
            ->assertCanNotSeeTableRecords([$today, $tomorrow]);
        Livewire::test(ListPersonnelAssignments::class)->filterTable('expiration', 'soon')
            ->assertCanSeeTableRecords([$today, $tomorrow]);
        $this->freeze('2026-10-27T04:00:00Z');
        $this->admin();
        Livewire::test(ListPersonnelAssignments::class)->filterTable('expiration', 'expired')
            ->assertCanSeeTableRecords([$today])->assertCanNotSeeTableRecords([$tomorrow]);
    }

    public function test_issue_and_return_forms_default_to_the_eastern_date(): void
    {
        $this->freeze('2026-10-27T00:01:00Z');
        $employee = $this->employee();
        $assignment = $this->equipment($employee, '2026-12-01');
        $uniform = Uniform::query()->create(['item_name' => 'Synthetic shirt', 'size' => 'L', 'quantity_on_hand' => 1, 'reorder_level' => 0]);
        $this->admin();
        Livewire::test(ViewPersonnelEmployee::class, ['record' => $employee->id])->mountAction('assign_ppe')
            ->assertSet('mountedActionsData.0.issued_at', '2026-10-26');
        Livewire::test(ViewPersonnelEmployee::class, ['record' => $employee->id])->mountAction('issue_uniform')
            ->assertSet('mountedActionsData.0.issued_at', '2026-10-26');
        Livewire::test(EditPersonnelAssignment::class, ['record' => $assignment->id])->mountAction('return')
            ->assertSet('mountedActionsData.0.returned_at', '2026-10-26');
        Livewire::test(ListUniforms::class)->call('loadTable')->mountTableAction('issue', $uniform)
            ->assertSet('mountedTableActionsData.0.issued_at', '2026-10-26');
        self::assertSame(1, $uniform->fresh()->quantity_on_hand);
        self::assertSame('active', $assignment->fresh()->status);
    }

    public function test_jacket_cycle_begins_on_the_anniversary_at_eastern_midnight(): void
    {
        $employee = $this->employee();
        AssignedEquipment::query()->create(['employee_portal_id' => $employee->id, 'category' => 'Jacket',
            'item_description' => 'Synthetic jacket', 'quantity' => 1, 'issued_at' => '2023-10-27', 'status' => 'active']);
        $service = app(UniformEntitlementService::class);
        $this->freeze('2026-10-27T00:01:00Z');
        self::assertFalse($service->jacketEligibility($employee)['can_order']);
        $this->freeze('2026-10-27T04:00:00Z');
        self::assertTrue($service->jacketEligibility($employee)['can_order']);
    }

    public function test_manual_jacket_issue_date_validation_does_not_accept_tomorrow_in_eastern_time(): void
    {
        $employee = $this->employee();
        $service = app(UniformEntitlementService::class);
        $this->freeze('2026-10-27T00:01:00Z');
        DB::transaction(fn () => $service->assertJacketIssueAllowed($employee, 1, '2026-10-26'));
        try {
            DB::transaction(fn () => $service->assertJacketIssueAllowed($employee, 1, '2026-10-27'));
            self::fail('Tomorrow in Eastern time must not be accepted as an issue date.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('issued_at', $exception->errors());
        }
        $this->freeze('2026-10-27T04:00:00Z');
        DB::transaction(fn () => $service->assertJacketIssueAllowed($employee, 1, '2026-10-27'));
        self::assertSame(0, AssignedEquipment::query()->count());
        Notification::assertNothingSent();
    }

    public function test_expiration_command_does_not_notify_a_day_early_at_utc_midnight(): void
    {
        config()->set('personnel_requests.expiration_thresholds', [0]);
        $employee = $this->employee();
        $this->equipment($employee, '2026-10-27');
        $this->freeze('2026-10-27T00:01:00Z');
        $this->artisan('personnel-equipment:notify-expirations')->assertSuccessful();
        self::assertSame(0, EquipmentExpirationNotification::query()->count());
        Notification::assertNothingSent();
        $this->freeze('2026-10-27T04:00:00Z');
        $this->artisan('personnel-equipment:notify-expirations')->assertSuccessful();
        self::assertSame(1, EquipmentExpirationNotification::query()->count());
        Notification::assertSentTo($employee, \Filament\Notifications\DatabaseNotification::class,
            fn ($notification): bool => $notification->data['title'] === 'Assigned equipment expiring soon');
    }

    public function test_annual_usage_resets_on_eastern_new_year_and_queries_utc_instant_bounds(): void
    {
        $employee = $this->employee();
        $this->request($employee, '2026-12-31 23:59:59', 2);
        $this->request($employee, '2027-01-01 00:00:00', 3);
        $service = app(UniformAllowanceService::class);
        $this->freeze('2027-01-01T00:01:00Z');
        $period = $service->period();
        self::assertSame('2026', $period['label']);
        self::assertSame('2026-01-01T05:00:00+00:00', $period['start']->toIso8601String());
        self::assertSame('2027-01-01T05:00:00+00:00', $period['end']->toIso8601String());
        self::assertSame(5, $service->usedByGroup($employee)['tshirts']);
        $this->freeze('2027-01-01T05:00:00Z');
        self::assertSame('2027', $service->period()['label']);
        self::assertSame([], $service->usedByGroup($employee));
        $this->request($employee, '2027-01-01 05:00:00', 1);
        self::assertSame(1, $service->usedByGroup($employee)['tshirts']);
    }

    private function freeze(string $instant): void
    {
        $time = CarbonImmutable::parse($instant);
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
    }

    private function employee(): Employee
    {
        return Employee::query()->create(['employee_id' => 'EASTERN-'.Str::random(8), 'name' => 'Synthetic calendar member',
            'rank' => 'Firefighter', 'roster_status' => 'active', 'must_change_password' => false]);
    }

    private function equipment(Employee $employee, string $expires): AssignedEquipment
    {
        return AssignedEquipment::query()->create(['employee_portal_id' => $employee->id, 'category' => 'Helmet',
            'item_description' => 'Synthetic calendar helmet '.$expires, 'quantity' => 1, 'issued_at' => '2026-01-01',
            'expires_at' => $expires, 'status' => 'active']);
    }

    private function admin(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function request(Employee $employee, string $createdAt, int $quantity): void
    {
        $key = (string) Str::ulid();
        $request = PersonnelRequest::query()->create(['public_id' => $key, 'request_number' => 'EAST-'.$key,
            'beneficiary_employee_id' => $employee->id, 'requester_employee_id' => $employee->id,
            'beneficiary_name' => $employee->name, 'beneficiary_employee_number' => $employee->employee_id,
            'requester_name' => $employee->name, 'requester_employee_number' => $employee->employee_id,
            'type' => 'uniform', 'status' => 'pending', 'idempotency_key' => $key, 'created_at' => $createdAt]);
        $request->items()->create(['item_code' => 't_shirt', 'item_name' => 'Synthetic shirt', 'category' => 'uniform', 'quantity' => $quantity]);
    }
}
