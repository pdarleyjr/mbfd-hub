<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Filament\Pages\MasterPersonnelDirectory;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Models\Employee;
use App\Models\User;
use App\Services\Personnel\PersonnelTableService;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

/** One personnel list: the Personnel Directory carries the old Employees list's account columns and links. */
final class PersonnelDirectoryConsolidationTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
    }

    public function test_directory_loads_with_login_and_invitation_columns_and_login_status_filter(): void
    {
        $withoutAccount = $this->employee('Awaiting Account', '61001');
        $active = $this->employee('Active Login', '61002');
        User::factory()->create(['employee_profile_id' => $active->id, 'employee_id' => '61002', 'account_status' => 'active']);
        $this->actingAsSuperAdmin();

        $this->get('/admin/master-personnel-directory')->assertOk()->assertSee('Personnel Directory');
        Livewire::test(MasterPersonnelDirectory::class)
            ->assertTableColumnExists('login')->assertTableColumnExists('invitation')
            ->assertTableColumnVisible('login')
            ->assertTableFilterExists('login_status')
            ->assertCanSeeTableRecords([$withoutAccount, $active])
            ->filterTable('login_status', 'awaiting_account')
            ->assertCanSeeTableRecords([$withoutAccount])->assertCanNotSeeTableRecords([$active])
            ->filterTable('login_status', 'active')
            ->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$withoutAccount])
            ->assertActionVisible('accountsOnboarding');
    }

    public function test_rows_open_the_profile_for_profile_viewers_and_details_for_directory_only_viewers(): void
    {
        $employee = $this->employee('Row Target', '61003');
        $this->actingAsSuperAdmin();
        $page = Livewire::test(MasterPersonnelDirectory::class);
        self::assertSame(EmployeeResource::getUrl('edit', ['record' => $employee]), $page->instance()->getTable()->getRecordUrl($employee));

        $this->actingAsAdminWith(['personnel.directory.view']);
        $page = Livewire::test(MasterPersonnelDirectory::class)
            ->assertCanSeeTableRecords([$employee])
            ->assertTableColumnHidden('login')
            ->assertActionHidden('accountsOnboarding');
        self::assertNull($page->instance()->getTable()->getRecordUrl($employee));
        self::assertSame('details', $page->instance()->getTable()->getRecordAction($employee));
    }

    public function test_navigation_has_a_single_personnel_directory_entry(): void
    {
        $this->actingAsSuperAdmin();
        $this->get('/admin/master-personnel-directory')->assertOk();

        self::assertSame('Personnel Directory', MasterPersonnelDirectory::getNavigationLabel());
        self::assertSame(10, MasterPersonnelDirectory::getNavigationSort());
        self::assertFalse(EmployeeResource::shouldRegisterNavigation());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $labels = collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn (NavigationItem $item): string => (string) $item->getLabel());
        self::assertSame(1, $labels->filter(fn (string $label): bool => $label === 'Personnel Directory')->count());
        self::assertSame(0, $labels->filter(fn (string $label): bool => $label === 'Employees')->count());
    }

    public function test_every_employee_page_still_responds_for_an_administrator(): void
    {
        $employee = $this->employee('Page Target', '61004');
        $this->actingAsSuperAdmin();

        $this->get(EmployeeResource::getUrl('index'))->assertOk()->assertSee('Accounts &amp; Onboarding', false);
        $this->get(EmployeeResource::getUrl('create'))->assertOk();
        $this->get(EmployeeResource::getUrl('edit', ['record' => $employee]))->assertOk()
            ->assertSee('Documents &amp; records', false)->assertSee(MasterPersonnelDirectory::getUrl(), false);
    }

    public function test_employee_pages_refuse_a_member_without_personnel_access(): void
    {
        $employee = $this->employee('Page Target', '61005');
        $this->actingAsAdminWith([]);

        $this->get(EmployeeResource::getUrl('index'))->assertForbidden();
        $this->get(EmployeeResource::getUrl('create'))->assertForbidden();
        $this->get(EmployeeResource::getUrl('edit', ['record' => $employee]))->assertForbidden();
    }

    public function test_additional_details_tab_appears_only_when_profile_fields_exist(): void
    {
        $employee = $this->employee('Tab Target', '61006');
        $actor = $this->actingAsSuperAdmin();
        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->assertSee('Documents & records')->assertDontSee('Additional details');

        $service = app(PersonnelTableService::class);
        $table = $service->master($actor);
        $service->update($actor, $table, ['columns' => [
            ['id' => (string) Str::uuid(), 'label' => 'Locker number', 'type' => 'text', 'profile_section' => 'Station details',
                'view_access' => 'personnel_managers'],
        ]], $table->version);

        Livewire::test(EditEmployee::class, ['record' => $employee->id])->assertSee('Additional details');

        // A read-only personnel viewer opens the profile but not a field limited to directory editors.
        $this->actingAsAdminWith(['admin.personnel.view']);
        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->assertSee('Documents & records')->assertDontSee('Additional details');
        Livewire::test(MasterPersonnelDirectory::class)->assertTableColumnDoesNotExist('tracking_'.$table->fresh()->columns[0]['id']);
    }

    private function employee(string $name, string $id): Employee
    {
        return Employee::query()->create(['employee_id' => $id, 'name' => $name, 'rank' => 'Firefighter',
            'station' => '1', 'roster_status' => 'active', 'city_email' => 'fixture'.$id.'@miamibeachfl.gov']);
    }
}
