<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Enums\AccountStatus;
use App\Models\AccidentReport;
use App\Models\Employee;
use App\Models\User;
use App\Services\Dashboards\DashboardReadModel;
use App\Services\Dashboards\DashboardSnapshot;
use App\Services\Dashboards\Sources\Station\AccidentReportSource;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\DashboardView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class AccidentAlertAndAdminTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function user(array $roles = [], array $permissions = []): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'User '.++$this->sequence, 'must_change_password' => false]);
        foreach ($roles as $role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function report(): AccidentReport
    {
        $creator = $this->user();
        $employee = Employee::query()->create(['employee_id' => '55501', 'name' => 'Alex Driver', 'rank' => 'Driver Engineer', 'password' => 'x', 'must_change_password' => false]);

        return AccidentReport::query()->create([
            'reference' => 'AR-261105-TEST', 'report_type' => AccidentReport::TYPE_VEHICLE, 'status' => AccidentReport::STATUS_AWAITING_SUPERVISOR,
            'created_by_user_id' => $creator->id, 'client_key' => (string) Str::uuid(), 'reporter_employee_id' => $employee->id,
            'subject_employee_id' => $employee->id, 'driver_data' => ['injuries' => 'Fractured left wrist', 'location' => 'Meridian Ave'],
            'supervisor_data' => [], 'revision' => 3, 'station_label' => 'Station 2', 'occurred_at' => now()->subHour(), 'submitted_at' => now(),
        ]);
    }

    public function test_the_alert_is_a_high_priority_personnel_row_without_confidential_details(): void
    {
        $report = $this->report();
        $admin = $this->user(['super_admin']);

        $snapshot = app(DashboardReadModel::class)->load($admin, DashboardView::Overview, fresh: true);
        $rows = array_values(array_filter($snapshot->rowsIn(DashboardSnapshot::LANE_MESSAGES), fn ($row) => $row->type === 'accident_report'));

        $this->assertCount(1, $rows);
        $this->assertSame('Accident reported. Action required.', $rows[0]->title);
        $this->assertSame('high', $rows[0]->severity);
        $this->assertStringContainsString('Alex Driver', (string) $rows[0]->summary);
        $detail = app(AccidentReportSource::class)->detail($admin, (string) $report->id);
        $this->assertStringContainsString('Fractured left wrist', json_encode($detail->facts), 'Administrators see everything.');
        // Never on a wall display.
        $this->assertSame([], app(AccidentReportSource::class)->rows($admin, DashboardQuery::now(displayMode: true), 50));
    }

    public function test_acknowledging_records_who_saw_it_but_keeps_the_accident_open(): void
    {
        $report = $this->report();
        $manager = $this->user(['super_admin']);
        $source = app(AccidentReportSource::class);
        $version = $source->rows($manager, DashboardQuery::now(), 50)[0]->version;

        $this->assertSame('done', $source->perform($manager, (string) $report->id, 'acknowledge', [], $version)->outcome);
        $this->assertSame('done', $source->perform($manager, (string) $report->id, 'acknowledge', [], $version)->outcome, 'A repeat is a no-op.');

        $report->refresh();
        $this->assertSame($manager->id, $report->alert_acknowledged_by_user_id);
        $this->assertNull($report->resolved_at);
        $rows = $source->rows($manager, DashboardQuery::now(), 50);
        $this->assertSame('in_progress', $rows[0]->stage, 'Still listed until resolved.');
        $this->assertSame(1, $report->events()->where('action', 'acknowledged')->count());
    }

    public function test_any_administrator_can_acknowledge_but_resolving_needs_the_manage_permission(): void
    {
        $report = $this->report();
        $admin = $this->user([], ['admin.access']);
        $source = app(AccidentReportSource::class);

        $this->assertSame('done', $source->perform($admin, (string) $report->id, 'acknowledge', [], $source->rows($admin, DashboardQuery::now(), 50)[0]->version)->outcome);
        $this->assertSame($admin->id, $report->fresh()->alert_acknowledged_by_user_id);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\Accidents\AccidentReportService::class)->resolve($admin, $report->fresh(), 'Handled');
    }

    public function test_a_non_administrator_without_access_never_sees_the_alert(): void
    {
        $this->report();
        $member = $this->user([], ['personnel.documents.view']);

        $this->assertSame([], app(AccidentReportSource::class)->rows($member, DashboardQuery::now(), 50));
    }

    public function test_the_restricted_report_page_opens_only_with_accident_access(): void
    {
        $report = $this->report();

        $this->actingAs($this->user(['super_admin']))->get('/admin/accident-reports/'.$report->ulid)->assertOk()->assertSee('AR-261105-TEST')->assertSee('Fractured left wrist');
        $this->actingAs($this->user([], ['admin.access', 'personnel.accidents.view']))->get('/admin/accident-reports/'.$report->ulid)->assertOk();
        // Every administrator opens it in full (owner decision): it is not a medical record.
        $this->actingAs($this->user([], ['admin.access']))->get('/admin/accident-reports/'.$report->ulid)->assertOk()->assertSee('Fractured left wrist');
        $this->actingAs($this->user([], ['admin.access']))->get('/admin/accident-reports')->assertOk();
        // An account without admin access does not.
        $this->actingAs($this->user([], ['personnel.documents.view']))->get('/admin/accident-reports/'.$report->ulid)->assertForbidden();
    }
}
