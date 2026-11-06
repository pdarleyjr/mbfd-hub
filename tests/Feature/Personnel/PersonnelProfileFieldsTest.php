<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Filament\Pages\MasterPersonnelDirectory;
use App\Livewire\Personnel\EmployeeProfileFieldsPanel;
use App\Models\Employee;
use App\Models\PersonnelTable;
use App\Models\User;
use App\Services\Personnel\PersonnelTableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

/** Profile fields an administrator adds without code: master-directory columns placed on profiles. */
final class PersonnelProfileFieldsTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    private string $locker;

    private string $badge;

    private string $website;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
        $this->locker = (string) Str::uuid();
        $this->badge = (string) Str::uuid();
        $this->website = (string) Str::uuid();
    }

    public function test_stored_definitions_without_profile_keys_still_validate_and_stay_off_profiles(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $service = app(PersonnelTableService::class);
        $table = $service->master($actor);
        $legacy = ['id' => $this->locker, 'label' => 'Legacy flag', 'type' => 'checkbox', 'options' => [], 'hidden' => false, 'color' => null,
            'highlight_when' => 'always', 'highlight_value' => null];
        $table->forceFill(['columns' => [$legacy]])->save();

        $updated = $service->update($actor, $table->fresh(), ['columns' => $table->fresh()->columns], $table->fresh()->version);

        self::assertEquals([$legacy], $updated->columns);
        foreach (PersonnelTableService::PROFILE_KEYS as $key) {
            self::assertArrayNotHasKey($key, $updated->columns[0]);
        }
        self::assertSame([], PersonnelTableService::profileSections($updated));
        self::assertFalse(EmployeeProfileFieldsPanel::hasFieldsFor($actor));
    }

    public function test_definition_validates_profile_keys_and_link_type(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $service = app(PersonnelTableService::class);
        $table = $service->master($actor);

        foreach ([['view_access' => 'everyone'], ['profile_section' => str_repeat('x', 61)], ['type' => 'hyperlink']] as $invalid) {
            try {
                $service->update($actor, $table, ['columns' => [['id' => $this->locker, 'label' => 'Bad', 'type' => 'text', ...$invalid]]], $table->version);
                self::fail('An invalid profile definition was accepted.');
            } catch (ValidationException) {
                self::assertSame([], $table->fresh()->columns);
            }
        }

        $table = $this->defineFields($actor);
        $employee = $this->employee('61201');
        try {
            $service->setCell($actor, $table, $employee, $this->website, 'javascript:alert(1)', 0);
            self::fail('A non-http link was stored.');
        } catch (ValidationException) {
            self::assertSame(0, $table->entries()->count());
        }
        $entry = $service->setCell($actor, $table, $employee, $this->website, 'https://example.org/cert', 0);
        self::assertSame('https://example.org/cert', $entry->values[$this->website]);
        self::assertSame('https://example.org/cert', PersonnelTableService::safeLink($entry->values[$this->website]));
        self::assertNull(PersonnelTableService::safeLink('javascript:alert(1)'));
        self::assertSame(['Station details', 'Credentials'], array_keys(PersonnelTableService::profileSections($table)));
    }

    public function test_profile_edit_saves_through_the_directory_with_history_and_enforces_required(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $table = $this->defineFields($actor);
        $employee = $this->employee('61202');

        Livewire::test(EmployeeProfileFieldsPanel::class, ['record' => $employee])
            ->assertSee('Station details')->assertSee('Locker number')->assertSee('Credentials')
            ->set('data.values.'.$this->locker, '')
            ->call('save')
            ->assertHasErrors(['data.values.'.$this->locker]);
        self::assertSame(0, $table->entries()->count());

        Livewire::test(EmployeeProfileFieldsPanel::class, ['record' => $employee])
            ->set('data.values.'.$this->locker, 'B-14')
            ->set('data.values.'.$this->website, 'https://example.org/badge')
            ->call('save')
            ->assertHasNoErrors();

        $entry = $table->entries()->where('employee_id', $employee->id)->sole();
        self::assertSame('B-14', $entry->values[$this->locker]);
        self::assertSame('https://example.org/badge', $entry->values[$this->website]);
        self::assertArrayNotHasKey($this->badge, $entry->values, 'Unchanged fields are not written.');
        self::assertSame(2, $table->events()->where('action', 'cell_updated')->count());

        // The directory reads the same record.
        Livewire::test(MasterPersonnelDirectory::class)->assertTableColumnStateSet('tracking_'.$this->locker, 'B-14', $employee);
    }

    public function test_directory_viewer_sees_fields_read_only_and_cannot_save(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $table = $this->defineFields($actor);
        $employee = $this->employee('61203');

        $this->actingAsAdminWith(['admin.personnel.view']);
        Livewire::test(EmployeeProfileFieldsPanel::class, ['record' => $employee])
            ->assertSee('Locker number')->assertDontSee('Save additional details')
            ->set('data.values.'.$this->locker, 'Z-1')
            ->call('save')->assertForbidden();
        self::assertSame(0, $table->entries()->count());
    }

    public function test_configure_columns_form_saves_profile_placement(): void
    {
        $actor = $this->actingAsSuperAdmin();
        app(PersonnelTableService::class)->master($actor);

        Livewire::test(MasterPersonnelDirectory::class)
            ->callAction('configureColumns', data: [
                'columns' => [[
                    'id' => $this->locker, 'label' => 'Locker number', 'type' => 'text', 'options' => [], 'color' => '',
                    'hidden' => false, 'view_access' => 'directory', 'profile_section' => ' Station details ',
                    'profile_order' => '2', 'required' => true,
                ]],
            ])
            ->assertHasNoActionErrors();

        $column = PersonnelTable::query()->where('directory_key', 'master')->sole()->columns[0];
        self::assertSame('Station details', $column['profile_section']);
        self::assertSame(2, $column['profile_order']);
        self::assertTrue($column['required']);
        self::assertSame('directory', $column['view_access']);
    }

    private function defineFields(User $actor): PersonnelTable
    {
        $service = app(PersonnelTableService::class);
        $table = $service->master($actor);

        return $service->update($actor, $table, ['columns' => [
            ['id' => $this->locker, 'label' => 'Locker number', 'type' => 'text', 'profile_section' => 'Station details', 'profile_order' => 1, 'required' => true],
            ['id' => $this->badge, 'label' => 'Badge issued', 'type' => 'checkbox', 'profile_section' => 'Station details', 'profile_order' => 2],
            ['id' => $this->website, 'label' => 'Certificate link', 'type' => 'link', 'profile_section' => 'Credentials', 'profile_order' => 5],
        ]], $table->version);
    }

    private function employee(string $id): Employee
    {
        return Employee::query()->create(['employee_id' => $id, 'name' => 'Member '.$id, 'rank' => 'Firefighter',
            'station' => '1', 'roster_status' => 'active']);
    }
}
