<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Filament\Pages\MasterPersonnelDirectory;
use App\Models\Employee;
use App\Services\Personnel\PersonnelTableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

final class MasterPersonnelDirectoryDetailsTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
    }

    public function test_search_covers_identity_and_email_when_only_an_assignment_column_is_displayed(): void
    {
        $member = $this->employee('Searchable Fixture', '00421', 'searchable.fixture@miamibeachfl.gov');
        $other = $this->employee('Other Fixture', '00422', 'other.fixture@miamibeachfl.gov');
        $actor = $this->actingAsSuperAdmin();
        $service = app(PersonnelTableService::class);
        $table = $service->master($actor);
        $service->update($actor, $table, ['source_columns' => ['shift']], $table->version);
        $this->actingAsAdminWith(['admin.personnel.view']);

        $page = Livewire::test(MasterPersonnelDirectory::class);
        self::assertTrue($page->instance()->getTable()->isSearchable());
        $page->assertTableColumnDoesNotExist('name')->assertTableColumnDoesNotExist('employee_id')
            ->assertTableColumnDoesNotExist('city_email');
        foreach (['00421', 'SEARCHABLE FIXTURE', 'searchable.fixture@miamibeachfl.gov'] as $search) {
            $page->searchTable($search)->assertCanSeeTableRecords([$member])->assertCanNotSeeTableRecords([$other]);
        }
    }

    public function test_readonly_details_show_complete_escaped_tracking_notes_without_edit_access(): void
    {
        $member = $this->employee('Notes Fixture', '00423', 'notes.fixture@miamibeachfl.gov');
        $actor = $this->actingAsSuperAdmin();
        $service = app(PersonnelTableService::class);
        $table = $service->master($actor);
        $columnId = (string) Str::uuid();
        $table = $service->update($actor, $table, ['columns' => [
            ['id' => $columnId, 'label' => 'Administrative notes', 'type' => 'notes'],
        ]], $table->version);
        $notes = str_repeat('Long administrative note. ', 12).'<script>alert("fixture")</script> Final follow-up instructions.';
        $service->setCell($actor, $table, $member, $columnId, $notes, 0);
        $before = $member->fresh()->getRawOriginal();
        $this->actingAsAdminWith(['admin.personnel.view']);

        Livewire::test(MasterPersonnelDirectory::class)
            ->assertTableActionHidden('editTracking', $member)
            ->mountTableAction('details', $member)
            ->assertTableActionMounted('details')
            ->assertSee('Administrative notes')
            ->assertSee($notes)
            ->assertDontSee('<script>alert("fixture")</script>', escape: false);

        self::assertSame($notes, $table->entries()->where('employee_id', $member->id)->sole()->values[$columnId]);
        self::assertSame($before, $member->fresh()->getRawOriginal());
    }

    private function employee(string $name, string $id, string $email): Employee
    {
        return Employee::query()->create(['employee_id' => $id, 'name' => $name, 'rank' => 'Firefighter',
            'station' => '1', 'roster_status' => 'active', 'city_email' => $email]);
    }
}
