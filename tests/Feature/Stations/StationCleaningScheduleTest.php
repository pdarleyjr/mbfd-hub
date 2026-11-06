<?php

declare(strict_types=1);

namespace Tests\Feature\Stations;

use App\Enums\AccountStatus;
use App\Filament\Resources\StationResource\Pages\ManageStationCleaning;
use App\Filament\Resources\StationResource\Pages\ViewStation;
use App\Models\Station;
use App\Models\StationCleaningTask;
use App\Models\StationCleaningTaskRevision;
use App\Models\User;
use App\Services\Stations\StationCleaningSchedule;
use App\Services\Stations\StationCleaningScheduleEditor;
use App\Support\HubTimezone;
use App\Support\Stations\StationCleaningAccess;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StationCleaningScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const SUNDAY_NOTE = 'No rotating assignment on Sunday in the department schedule.';

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_each_weekday_shows_the_department_focus(): void
    {
        $station = $this->station('1');
        $expected = [
            '2026-11-02' => ['Monday', 'GI Kitchen and Dining areas'],
            '2026-11-03' => ['Tuesday', 'GI Bathrooms and gym (includes cardio rooms)'],
            '2026-11-04' => ['Wednesday', 'Bedrooms'],
            '2026-11-05' => ['Thursday', 'Common areas'],
            '2026-11-06' => ['Friday', 'Bay, bunker gear rooms and outside areas'],
            '2026-11-07' => ['Saturday', 'Vehicles'],
        ];

        foreach ($expected as $date => [$weekday, $focus]) {
            $today = $this->schedule($station, $date);
            self::assertSame($weekday, $today['weekday'], $date);
            self::assertSame($date, $today['date']);
            self::assertSame($focus, $today['focus']['title'] ?? null, $date);
            self::assertNotSame([], $today['focus']['instructions'], $date);
            self::assertNull($today['note'], $date);
        }

        $monday = $this->schedule($station, '2026-11-02');
        self::assertStringContainsString('dishwasher filter, TV, ice machine', $monday['focus']['instructions'][0]);
        self::assertSame('Monday, November 2', $monday['date_label']);
        self::assertSame(['Vacuum and clean all vehicle cabs, compartments and equipment.'], $this->schedule($station, '2026-11-07')['focus']['instructions']);
    }

    public function test_friday_decon_room_is_only_for_station_2(): void
    {
        $one = $this->station('1');
        $two = $this->station('2');

        $friday = $this->schedule($two, '2026-11-06');
        self::assertSame(['Decon room (Station 2)'], array_column($friday['extras'], 'title'));
        self::assertTrue($friday['extras'][0]['station_specific']);
        self::assertSame(['Wash and scrub.'], $friday['extras'][0]['instructions']);
        self::assertSame('Bay, bunker gear rooms and outside areas', $friday['focus']['title']);

        self::assertSame([], $this->schedule($one, '2026-11-06')['extras'], 'Station 1 has no Decon task.');
        self::assertSame([], $this->schedule($two, '2026-11-05')['extras'], 'Decon is a Friday task only.');
        foreach (['3', '4', '6'] as $number) {
            self::assertSame([], $this->schedule($this->station($number), '2026-11-06')['extras'], "Station {$number}");
        }
    }

    public function test_station_2_written_with_a_leading_zero_still_gets_decon(): void
    {
        $two = $this->station('02');

        self::assertSame(['Decon room (Station 2)'], array_column($this->schedule($two, '2026-11-13')['extras'], 'title'));
    }

    public function test_sunday_has_no_invented_assignment_and_keeps_the_baseline(): void
    {
        $sunday = $this->schedule($this->station('2'), '2026-11-08');

        self::assertSame('Sunday', $sunday['weekday']);
        self::assertNull($sunday['focus']);
        self::assertSame(self::SUNDAY_NOTE, $sunday['note']);
        self::assertSame([], $sunday['extras']);
        self::assertNotNull($sunday['baseline']);
        self::assertSame(0, StationCleaningTask::query()->where('weekday', 7)->count(), 'The migration seeds nothing for Sunday.');
    }

    public function test_the_every_day_baseline_is_present_on_every_day(): void
    {
        $station = $this->station('3');
        foreach (range(2, 8) as $day) {
            $baseline = $this->schedule($station, sprintf('2026-11-%02d', $day))['baseline'];
            self::assertNotNull($baseline);
            self::assertSame('Every day', $baseline['title']);
            self::assertSame('Every day should still include general clean up and normal station duties.', $baseline['instructions'][0]);
            self::assertCount(3, $baseline['instructions']);
        }
        self::assertSame(StationCleaningSchedule::SOURCE_NOTE, $this->schedule($station, '2026-11-02')['source']);
    }

    public function test_today_follows_the_new_york_calendar_date_not_utc(): void
    {
        $this->freezeUtc('2026-11-07 03:30:00');

        $today = app(StationCleaningSchedule::class)->forStation($this->station('2'));

        self::assertSame('2026-11-06', $today['date']);
        self::assertSame('Friday', $today['weekday']);
        self::assertSame(['Decon room (Station 2)'], array_column($today['extras'], 'title'));
    }

    public function test_today_is_right_across_the_end_of_daylight_saving_time(): void
    {
        $station = $this->station('1');

        // 01:30 EDT, before clocks fall back at 06:00 UTC on Sunday November 1, 2026.
        $this->freezeUtc('2026-11-01 05:30:00');
        self::assertSame('Sunday', app(StationCleaningSchedule::class)->forStation($station)['weekday']);

        // 23:30 EST on Sunday: under the old -4 offset this would already be Monday.
        $this->freezeUtc('2026-11-02 04:30:00');
        $sunday = app(StationCleaningSchedule::class)->forStation($station);
        self::assertSame('2026-11-01', $sunday['date']);
        self::assertNull($sunday['focus']);

        $this->freezeUtc('2026-11-02 05:00:00');
        self::assertSame('GI Kitchen and Dining areas', app(StationCleaningSchedule::class)->forStation($station)['focus']['title']);
    }

    public function test_the_member_station_overview_carries_todays_cleaning(): void
    {
        $station = $this->station('2');
        $this->freezeUtc('2026-11-07 03:30:00');
        $this->actingAsCanonicalFixture();

        $this->getJson("/api/public/stations/{$station->id}/overview")
            ->assertOk()
            ->assertJsonPath('cleaning.date', '2026-11-06')
            ->assertJsonPath('cleaning.weekday', 'Friday')
            ->assertJsonPath('cleaning.focus.title', 'Bay, bunker gear rooms and outside areas')
            ->assertJsonPath('cleaning.extras.0.title', 'Decon room (Station 2)')
            ->assertJsonPath('cleaning.baseline.title', 'Every day');
    }

    public function test_an_admin_edit_shows_immediately_and_is_audited(): void
    {
        $station = $this->station('1');
        $admin = $this->admin(super: true);
        $editor = app(StationCleaningScheduleEditor::class);
        $state = $editor->formState($station);
        $state['weekly'][1]['title'] = 'Kitchen and dining';
        $state['weekly'][7] = ['id' => null, 'title' => 'Apparatus floor', 'instructions' => "Hose down the bay.\nCheck drains.", 'is_enabled' => true];

        self::assertSame(2, $editor->save($admin, $station, $state));

        self::assertSame('Kitchen and dining', $this->schedule($station, '2026-11-02')['focus']['title']);
        $sunday = $this->schedule($station, '2026-11-08');
        self::assertSame('Apparatus floor', $sunday['focus']['title']);
        self::assertSame(['Hose down the bay.', 'Check drains.'], $sunday['focus']['instructions']);
        self::assertNull($sunday['note']);

        $monday = StationCleaningTask::query()->where('kind', 'focus')->where('weekday', 1)->firstOrFail();
        $revision = StationCleaningTaskRevision::query()->where('station_cleaning_task_id', $monday->id)->sole();
        self::assertSame('updated', $revision->action);
        self::assertSame('GI Kitchen and Dining areas', $revision->before['title']);
        self::assertSame('Kitchen and dining', $revision->after['title']);
        self::assertSame($admin->id, $revision->changed_by_user_id);
        self::assertSame($admin->id, $monday->updated_by_user_id);

        self::assertSame(0, $editor->save($admin, $station, $editor->formState($station)), 'Saving unchanged state records nothing.');
        self::assertSame(2, StationCleaningTaskRevision::query()->count());

        $this->expectException(LogicException::class);
        $revision->update(['action' => 'changed']);
    }

    public function test_date_exceptions_replace_the_focus_or_add_a_task_for_that_date_only(): void
    {
        $one = $this->station('1');
        $two = $this->station('2');
        $editor = app(StationCleaningScheduleEditor::class);
        $admin = $this->admin(super: true);
        $state = $editor->formState($one);
        $state['tasks'][] = ['applies_to' => 'station', 'kind' => 'focus', 'when' => 'date', 'on_date' => '2026-11-09', 'title' => 'Inspection prep', 'instructions' => 'Ready the station for the chief.', 'is_enabled' => true];
        $state['tasks'][] = ['applies_to' => 'all', 'kind' => 'extra', 'when' => 'date', 'on_date' => '2026-11-09', 'title' => 'Flag pole', 'instructions' => '', 'is_enabled' => true];
        $editor->save($admin, $one, $state);

        $special = $this->schedule($one, '2026-11-09');
        self::assertSame('Inspection prep', $special['focus']['title']);
        self::assertTrue($special['focus']['date_exception']);
        self::assertSame(['Flag pole'], array_column($special['extras'], 'title'));

        $otherStation = $this->schedule($two, '2026-11-09');
        self::assertSame('GI Kitchen and Dining areas', $otherStation['focus']['title'], 'A station-only exception stays with that station.');
        self::assertSame(['Flag pole'], array_column($otherStation['extras'], 'title'), 'An all-station extra reaches every station.');

        self::assertSame('GI Kitchen and Dining areas', $this->schedule($one, '2026-11-16')['focus']['title'], 'The next Monday is back to normal.');
    }

    public function test_a_disabled_task_disappears_without_being_deleted(): void
    {
        $two = $this->station('2');
        $admin = $this->admin(permissions: [StationCleaningAccess::PERMISSION]);
        $editor = app(StationCleaningScheduleEditor::class);
        $state = $editor->formState($two);
        self::assertSame('Decon room (Station 2)', $state['tasks'][0]['title']);
        $state['tasks'][0]['is_enabled'] = false;

        $editor->save($admin, $two, $state);

        self::assertSame([], $this->schedule($two, '2026-11-06')['extras']);
        self::assertSame(1, StationCleaningTask::query()->where('title', 'Decon room (Station 2)')->count());
    }

    public function test_one_station_cannot_edit_another_stations_tasks(): void
    {
        $one = $this->station('1');
        $two = $this->station('2');
        $decon = StationCleaningTask::query()->where('station_number', '2')->sole();
        $editor = app(StationCleaningScheduleEditor::class);
        $state = $editor->formState($one);
        $state['tasks'][] = ['id' => $decon->id, 'applies_to' => 'station', 'kind' => 'extra', 'when' => 'weekday', 'weekday' => 5, 'title' => 'Taken over', 'instructions' => '', 'is_enabled' => true];

        try {
            $editor->save($this->admin(super: true), $one, $state);
            self::fail('Another station\'s task was accepted.');
        } catch (ValidationException) {
            self::assertSame('Decon room (Station 2)', $decon->fresh()->title);
        }
        self::assertSame(['Decon room (Station 2)'], array_column($this->schedule($two, '2026-11-06')['extras'], 'title'));
    }

    public function test_people_without_the_permission_cannot_edit_the_schedule(): void
    {
        $station = $this->station('1');
        $viewer = $this->admin(permissions: ['admin.access', 'admin.stations.view', 'admin.stations.manage']);
        $editor = app(StationCleaningScheduleEditor::class);

        self::assertFalse(StationCleaningAccess::canManage($viewer));
        try {
            $editor->save($viewer, $station, $editor->formState($station));
            self::fail('An unauthorized save was accepted.');
        } catch (AuthorizationException) {
            self::assertSame(0, StationCleaningTaskRevision::query()->count());
        }

        $this->actingAs($viewer);
        Livewire::test(ViewStation::class, ['record' => $station->id])
            ->assertSuccessful()
            ->assertSee('Station Cleaning')
            ->assertActionHidden('editCleaningSchedule');
        Livewire::test(ManageStationCleaning::class, ['record' => $station->id])->assertForbidden();
        $this->get("/admin/stations/{$station->id}/cleaning")->assertForbidden();
    }

    public function test_a_permission_holder_edits_through_the_admin_screen(): void
    {
        $station = $this->station('2');
        $manager = $this->admin(permissions: ['admin.access', 'admin.stations.view', StationCleaningAccess::PERMISSION]);
        $this->actingAs($manager);

        Livewire::test(ViewStation::class, ['record' => $station->id])->assertActionVisible('editCleaningSchedule');
        Livewire::test(ManageStationCleaning::class, ['record' => $station->id])
            ->assertSuccessful()
            ->assertSee('Weekly schedule (all stations)')
            ->assertSee('Decon room (Station 2)')
            ->set('data.weekly.3.title', 'Bedrooms and lockers')
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame('Bedrooms and lockers', $this->schedule($station, '2026-11-04')['focus']['title']);
        self::assertSame($manager->id, StationCleaningTaskRevision::query()->sole()->changed_by_user_id);
    }

    public function test_rollback_keeps_an_edited_schedule(): void
    {
        $station = $this->station('1');
        $editor = app(StationCleaningScheduleEditor::class);
        $state = $editor->formState($station);
        $state['weekly'][4]['title'] = 'Common rooms';
        $editor->save($this->admin(super: true), $station, $state);

        $migration = require database_path('migrations/2026_11_06_093000_create_station_cleaning_tasks.php');

        $this->expectException(LogicException::class);
        $migration->down();
    }

    /** @return array<string, mixed> */
    private function schedule(Station $station, string $date): array
    {
        return app(StationCleaningSchedule::class)->forStation($station, CarbonImmutable::parse($date, HubTimezone::NAME));
    }

    private function freezeUtc(string $utc): void
    {
        $now = CarbonImmutable::parse($utc, 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    private function station(string $number): Station
    {
        return Station::query()->create([
            'station_number' => $number,
            'name' => 'Station '.ltrim($number, '0'),
            'address' => '1 Test Way',
            'city' => 'Miami Beach',
            'state' => 'FL',
            'zip_code' => '33139',
            'is_active' => true,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function admin(bool $super = false, array $permissions = []): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Pat Example', 'display_name' => 'Pat Example', 'must_change_password' => false]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        if ($super) {
            $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
