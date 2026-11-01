<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Filament\Training\Pages\TrainingCrewPlanner;
use App\Models\Employee;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingCrewPlanDraft;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDraftStore;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/**
 * Independent workflow acceptance: planning and both downloads read the accepted TeleStaff roster and never
 * write to a training, attendance or personnel table. Every identity and roster row is synthetic.
 */
final class TrainingCrewPlannerIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-21';

    private User $admin;

    private TrainingCourse $course;

    /** @var array<string, Employee> */
    private array $employees = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->withoutVite();
        config(['captain5.shift_rotation' => ['enabled' => false]]);
        $now = CarbonImmutable::parse(self::DATE.' 07:30:00', \App\Support\HubTimezone::NAME);
        CarbonImmutable::setTestNow($now);
        \Illuminate\Support\Carbon::setTestNow($now);
        $this->admin = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $this->admin->assignRole(Role::findOrCreate('training_admin', 'web'));
        $this->actingAs($this->admin);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('training'));
        $this->course = TrainingCourse::query()->create(['name' => 'Independent synthetic course', 'classifications' => ['STC'],
            'track_attendance_matrix' => false, 'created_by' => $this->admin->id]);
        foreach (['90001' => 'Captain', '90002' => 'Firefighter', '90003' => 'Lieutenant'] as $number => $rank) {
            $this->employees[$number] = Employee::query()->create(['employee_id' => $number, 'name' => 'Synthetic '.$number,
                'rank' => $rank, 'roster_status' => 'active', 'password' => 'not-used']);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_planning_and_both_downloads_leave_every_domain_table_unchanged(): void
    {
        $this->roster(['90001' => ['08:00', '20:00'], '90002' => ['08:00', '20:00']]);
        $component = Livewire::test(TrainingCrewPlanner::class)->set('scheduleDate', self::DATE)->set('courseId', $this->course->id)->assertHasNoErrors();
        $draftId = $component->get('draftId');
        $before = $this->domainHashes();
        $plannerRowsBefore = TrainingCrewPlanDraft::query()->count();
        $this->enterSessions($component, [['Morning', '08:30', '11:30'], ['Afternoon', '13:00', '16:00']]);
        $slots = $this->draft($draftId)['slots'];
        self::assertCount(2, $slots);
        $first = $this->employees['90001']->id;
        $second = $this->employees['90002']->id;
        $component->call('toggleMember', $first)->assertHasNoErrors();
        self::assertCount(1, $this->draft($draftId)['selections']);
        $component->call('toggleMember', $second)->assertHasNoErrors();
        self::assertCount(2, $this->draft($draftId)['selections']);
        $component->call('toggleMember', $first);
        self::assertCount(1, $this->draft($draftId)['selections']);
        $component->call('undoRemove')->assertHasNoErrors();
        self::assertCount(2, $this->draft($draftId)['selections']);
        $component->set('planSelection', [(string) $first])->set('targetSlot', $slots[0]['id'])->call('moveSelected')->assertHasNoErrors();
        $component->set('planSelection', [(string) $second])->set('targetSlot', $slots[1]['id'])->call('moveSelected')->assertHasNoErrors();
        self::assertSame($slots[0]['id'], $this->draft($draftId)['selections'][$first]['slot_id']);
        self::assertSame($slots[1]['id'], $this->draft($draftId)['selections'][$second]['slot_id']);

        // Exporting only reads: the database itself refuses any write while the downloads run.
        DB::statement('PRAGMA query_only = ON');
        try {
            $component->call('download', 'pdf')->assertFileDownloaded('MBFD-Training-Class-Roster-'.self::DATE.'.pdf');
            $pdf = $this->downloadBytes($component);
            self::assertStringStartsWith('%PDF-', $pdf);
            $text = (new Parser)->parseContent($pdf)->getText();
            foreach (['Synthetic 90001', 'Synthetic 90002', 'Captain', 'Firefighter', 'Station 9', 'Engine 9'] as $expected) {
                self::assertStringContainsString($expected, $text);
            }
            self::assertStringNotContainsStringIgnoringCase('uploaded', $text);
            $component->call('download', 'xlsx')->assertFileDownloaded('MBFD-Training-Class-Roster-'.self::DATE.'.xlsx');
            $xlsx = $this->downloadBytes($component);
        } finally {
            DB::statement('PRAGMA query_only = OFF');
        }
        self::assertStringStartsWith('PK', $xlsx);
        $bookPath = tempnam(sys_get_temp_dir(), 'crew-isolation-xlsx-');
        try {
            file_put_contents($bookPath, $xlsx);
            $book = IOFactory::load($bookPath);
            try {
                self::assertSame('2', $book->getSheetByName('Summary')->getCell('B10')->getValue());
                self::assertSame('Captain', $book->getSheetByName('Morning')->getCell('C5')->getValue());
                self::assertStringContainsString('Engine 9', $book->getSheetByName('Afternoon')->getCell('F5')->getValue());
                foreach ($book->getAllSheets() as $sheet) {
                    foreach ($sheet->getCoordinates() as $coordinate) {
                        self::assertNotSame(DataType::TYPE_FORMULA, $sheet->getCell($coordinate)->getDataType());
                        self::assertStringNotContainsStringIgnoringCase('uploaded', (string) $sheet->getCell($coordinate)->getValue());
                    }
                }
            } finally {
                $book->disconnectWorksheets();
            }
        } finally {
            unlink($bookPath);
        }

        // Extending a session past the end of duty keeps both people and asks for a fresh look before export.
        $component->set('slotEdits.0.end', '21:00')->assertHasNoErrors();
        self::assertCount(2, $this->draft($draftId)['selections']);
        $component->call('download', 'pdf')->assertHasErrors('confirmReview')->assertNoFileDownloaded();
        $component->set('confirmReview', true)->call('download', 'pdf')->assertFileDownloaded();
        self::assertStringContainsStringIgnoringCase('Needs verification', (new Parser)->parseContent($this->downloadBytes($component))->getText());

        $component->call('discardProposal')->assertHasNoErrors();
        self::assertNotSame($draftId, $component->get('draftId'));
        self::assertSame([], $this->draft($component->get('draftId'))['selections']);
        self::assertSame($before, $this->domainHashes(), 'Planning and exporting changed a training, attendance or personnel table.');
        self::assertSame(0, TrainingAttendance::query()->count());
        self::assertSame(0, TrainingSession::query()->count());
        self::assertSame($plannerRowsBefore, TrainingCrewPlanDraft::query()->count(), 'Only the planner\'s own draft row moves.');
    }

    public function test_the_page_has_no_file_input_and_the_old_upload_address_is_gone(): void
    {
        $this->roster(['90001' => ['08:00', '20:00']]);
        self::assertFalse(Route::has('training.crew-planner.upload'));
        $html = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id)->assertDontSeeHtml('type="file"')
            ->assertDontSeeHtml('multipart/form-data')->assertDontSee('Daily TeleStaff PDF')->html();
        self::assertStringNotContainsString('/training/crew-planner/upload', $html);
        $before = $this->domainHashes();
        $this->post('/training/crew-planner/upload', ['draft_id' => (string) Str::uuid(), 'version' => 1])->assertNotFound();
        $this->post('/training/crew-planner/upload', [], ['Accept' => 'application/json'])->assertNotFound();
        self::assertSame($before, $this->domainHashes());
    }

    public function test_a_foreign_draft_a_locked_id_and_a_stale_version_are_refused_without_a_change(): void
    {
        $store = app(TrainingCrewPlannerDraftStore::class);
        $other = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $foreign = $store->create($other, self::DATE);
        $foreign = $store->update($other, $foreign['id'], 1, fn (array $row): array => [...$row, 'class_title' => 'Foreign synthetic class']);
        $before = $this->domainHashes();

        foreach ([
            fn () => $store->get($this->admin, $foreign['id']),
            fn () => $store->update($this->admin, $foreign['id'], $foreign['version'], fn (array $row): array => [...$row, 'class_title' => 'Taken over']),
            fn () => $store->discard($this->admin, $foreign['id'], $foreign['version']),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Another member\'s plan must behave like one that does not exist.');
            } catch (HttpException $exception) {
                self::assertSame(410, $exception->getStatusCode());
            }
        }
        self::assertSame($foreign, $store->get($other, $foreign['id']));

        $component = Livewire::test(TrainingCrewPlanner::class)->assertDontSee('Foreign synthetic class');
        self::assertNotSame($foreign['id'], $component->get('draftId'));
        try {
            $component->set('draftId', $foreign['id']);
            self::fail('The draft id is locked against browser edits.');
        } catch (CannotUpdateLockedPropertyException) {
            self::assertTrue(true);
        }

        $own = $this->draft($component->get('draftId'));
        $store->update($this->admin, $own['id'], $own['version'], static fn (array $row): array => $row);
        try {
            $store->update($this->admin, $own['id'], $own['version'], static fn (array $row): array => [...$row, 'class_title' => 'Stale overwrite']);
            self::fail('A stale tab must not overwrite a newer save.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
        self::assertSame('', $this->draft($own['id'])['class_title']);
        self::assertSame($before, $this->domainHashes());
    }

    public function test_viewer_and_revoked_management_access_are_denied_on_actual_actions(): void
    {
        $this->roster(['90001' => ['08:00', '20:00']]);
        $component = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id);
        $second = Livewire::test(TrainingCrewPlanner::class);
        $before = $this->draft($component->get('draftId'));
        $this->admin->removeRole('training_admin');
        $component->call('download', 'pdf')->assertForbidden();
        $second->call('toggleMember', $this->employees['90001']->id)->assertForbidden();
        $this->admin->assignRole(Role::findOrCreate('training_viewer', 'web'));
        Livewire::test(TrainingCrewPlanner::class)->assertForbidden();
        self::assertContains($this->get('/training/crew-planner')->getStatusCode(), [403, 404]);
        self::assertSame($before, app(TrainingCrewPlannerDraftStore::class)->get($this->admin, $before['id']));
    }

    public function test_anonymous_actor_cannot_open_the_page_and_the_old_upload_address_stays_a_404(): void
    {
        $this->roster(['90001' => ['08:00', '20:00']]);
        $draft = app(TrainingCrewPlannerDraftStore::class)->create($this->admin, self::DATE);
        $before = $this->domainHashes();
        $this->logoutCanonicalSession();
        $this->get('/training/crew-planner')->assertRedirect();
        $this->post('/training/crew-planner/upload', ['draft_id' => $draft['id'], 'version' => 1], ['Accept' => 'application/json'])->assertNotFound();
        self::assertSame($before, $this->domainHashes());
        self::assertSame($draft, app(TrainingCrewPlannerDraftStore::class)->get($this->admin, $draft['id']));
    }

    public function test_changing_the_date_keeps_selections_and_session_times_and_unconfirmed_days_wait_for_review(): void
    {
        $this->roster(['90001' => ['08:00', '20:00'], '90002' => ['08:00', '20:00']]);
        $component = Livewire::test(TrainingCrewPlanner::class)->set('scheduleDate', self::DATE)->set('courseId', $this->course->id);
        $this->enterSessions($component, [['Morning', '08:30', '11:30']]);
        $component->call('selectAllShown')->assertHasNoErrors();
        $id = $component->get('draftId');
        $selections = $this->draft($id)['selections'];
        self::assertCount(2, $selections);
        $before = $this->domainHashes();

        // No roster is on file for the new date, so nobody can be called on duty there.
        $component->set('scheduleDate', '2026-10-24')->assertHasNoErrors();
        $after = $this->draft($id);
        self::assertSame($selections, $after['selections'], 'Selections survive a date change.');
        self::assertSame('2026-10-24T08:30:00-04:00', $after['slots'][0]['start_at']);
        self::assertSame('2026-10-24T11:30:00-04:00', $after['slots'][0]['end_at']);
        $component->assertSeeHtml('data-roster-source="unavailable"')->assertSee('provisional');
        $component->call('download', 'pdf')->assertFileDownloaded('MBFD-Training-Class-Roster-2026-10-24.pdf');
        self::assertStringContainsString('no TeleStaff roster was available', (new Parser)->parseContent($this->downloadBytes($component))->getText());
        $component->call('download', 'xlsx')->assertFileDownloaded('MBFD-Training-Class-Roster-2026-10-24.xlsx');
        self::assertSame($after, $this->draft($id));

        // Back on the day with a roster, the same people are in the same session without any retyping.
        $component->set('scheduleDate', self::DATE)->assertHasNoErrors();
        self::assertSame($selections, $this->draft($id)['selections']);
        $component->assertSeeHtml('data-roster-source="verified"')->call('download', 'pdf')->assertFileDownloaded('MBFD-Training-Class-Roster-'.self::DATE.'.pdf');
        self::assertStringNotContainsString('no TeleStaff roster was available', (new Parser)->parseContent($this->downloadBytes($component))->getText());
        self::assertSame($before, $this->domainHashes());
    }

    public function test_a_roster_change_on_the_plans_date_keeps_the_selections_and_blocks_export_until_reviewed(): void
    {
        $this->roster(['90001' => ['08:00', '20:00'], '90002' => ['08:00', '20:00']]);
        $component = Livewire::test(TrainingCrewPlanner::class)->set('scheduleDate', self::DATE)->set('courseId', $this->course->id);
        $component->call('selectAllShown')->assertHasNoErrors();
        $id = $component->get('draftId');
        $selections = $this->draft($id)['selections'];
        self::assertCount(2, $selections);

        // A newer TeleStaff export sends one selected member home.
        DailyRosterSnapshotFactory::publish(self::DATE, ['Station 9 / Engine 9' => [
            $this->row('90001', 'SIC', 'not_working', '08:00', '20:00'),
            $this->row('90002', 'SP', 'working', '08:00', '20:00'),
        ]], ['shift' => 'B']);
        $component->call('refreshRoster')->assertSeeHtml('data-roster-changed');
        self::assertSame($selections, $this->draft($id)['selections']);
        $component->set('confirmReview', true);
        foreach (['pdf', 'xlsx'] as $format) {
            $component->call('download', $format)->assertHasErrors('plan')->assertNoFileDownloaded();
        }
        self::assertSame($selections, $this->draft($id)['selections']);

        $component->call('acknowledgeRoster')->assertDontSeeHtml('data-roster-changed');
        self::assertSame($selections, $this->draft($id)['selections'], 'Acknowledging never drops a member.');
        $component->call('download', 'pdf')->assertHasErrors('confirmReview')->assertNoFileDownloaded();
        $component->set('confirmReview', true)->call('download', 'pdf')->assertFileDownloaded();
        $text = (new Parser)->parseContent($this->downloadBytes($component))->getText();
        self::assertStringContainsStringIgnoringCase('Needs verification', $text);
        self::assertStringNotContainsString('SIC', $text, 'A leave code never reaches the export.');
    }

    public function test_a_session_that_runs_past_midnight_needs_roster_rows_that_cover_it(): void
    {
        // 90001 works 24 hours, 90002 the day half, 90003 the night half (20:00 to 08:00 the next morning).
        $this->roster(['90001' => ['08:00', '08:00'], '90002' => ['08:00', '20:00'], '90003' => ['20:00', '08:00']]);
        $store = app(TrainingCrewPlannerDraftStore::class);
        $component = Livewire::test(TrainingCrewPlanner::class)->set('scheduleDate', self::DATE)->set('courseId', $this->course->id);
        $id = $component->get('draftId');
        $slot = ['id' => (string) Str::uuid(), 'label' => 'Overnight', 'start_at' => '2026-10-21T23:00:00-04:00', 'end_at' => '2026-10-22T01:00:00-04:00'];
        $store->update($this->admin, $id, $this->draft($id)['version'], fn (array $row): array => [...$row, 'slots' => [$slot]]);
        $component = Livewire::test(TrainingCrewPlanner::class)->assertSet('draftId', $id);
        $component->assertSee('2 eligible')->assertSeeHtml('aria-label="Select Synthetic 90001"')->assertSeeHtml('aria-label="Select Synthetic 90003"')
            ->assertDontSeeHtml('aria-label="Select Synthetic 90002"');
        $component->call('toggleMember', $this->employees['90002']->id)->assertHasErrors('selection');
        self::assertSame([], $this->draft($id)['selections']);
        $component->call('selectAllShown')->assertHasNoErrors();
        self::assertCount(2, $this->draft($id)['selections']);
        self::assertSame([$slot['id']], array_values(array_unique(array_column($this->draft($id)['selections'], 'slot_id'))));
        $component->call('download', 'xlsx')->assertFileDownloaded('MBFD-Training-Class-Roster-'.self::DATE.'.xlsx');
        $component->call('download', 'pdf')->assertFileDownloaded('MBFD-Training-Class-Roster-'.self::DATE.'.pdf');
    }

    public function test_the_previous_days_night_shift_is_never_borrowed_for_a_date_with_no_roster(): void
    {
        // The 20th's night row runs until 08:00 on the 21st, yet a plan for the 21st reads only the 21st's roster.
        $this->roster(['90001' => ['20:00', '08:00']], '2026-10-20');
        $component = Livewire::test(TrainingCrewPlanner::class)->set('scheduleDate', self::DATE)->set('courseId', $this->course->id);
        $component->assertSeeHtml('data-roster-source="unavailable"')->assertDontSee('On duty 20:00')->assertDontSee('On duty');
        $component->set('slotEdits.0.start', '03:00')->set('slotEdits.0.end', '06:00')->assertHasNoErrors();
        $component->assertSee('Duty not confirmed')->assertDontSee('On duty');
        $duty = app(\App\Services\Training\CrewPlanner\TrainingRosterDutySource::class)->forDate(self::DATE);
        self::assertSame([], $duty->staff);
        self::assertFalse($duty->isVerified());
    }

    public function test_admin_alias_requires_training_management_even_with_admin_panel_access(): void
    {
        $this->admin->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $this->admin->assignRole(Role::findOrCreate('admin', 'web'));
        $response = $this->get('/admin/crew-planner')->assertOk();
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->admin->removeRole('training_admin');
        $this->admin->assignRole(Role::findOrCreate('training_viewer', 'web'));
        $this->get('/admin/crew-planner')->assertForbidden();
    }

    /**
     * A published TeleStaff roster. Each entry is employee id => [from, through], all at Station 9 / Engine 9.
     *
     * @param  array<string, array{string, string}>  $duty
     */
    private function roster(array $duty, string $date = self::DATE): void
    {
        $rows = [];
        foreach ($duty as $number => [$from, $through]) {
            $rows[] = $this->row((string) $number, 'SP', 'working', $from, $through);
        }
        DailyRosterSnapshotFactory::publish($date, ['Station 9 / Engine 9' => $rows], ['shift' => 'B']);
    }

    private function row(string $number, string $code, string $availability, string $from, string $through): array
    {
        return ['employee_id' => $number, 'name' => 'Synthetic '.$number, 'code' => $code, 'availability' => $availability, 'from' => $from, 'through' => $through];
    }

    /** Types real session rows; each row saves itself once both times are valid. */
    private function enterSessions(mixed $component, array $sessions): void
    {
        foreach ($sessions as $index => [$label, $start, $end]) {
            if ($index > 0) {
                $component->call('addSlot');
            }
            $component->set("slotEdits.$index.label", $label)->set("slotEdits.$index.start", $start)->set("slotEdits.$index.end", $end)->assertHasNoErrors();
        }
    }

    private function draft(string $id): array
    {
        return app(TrainingCrewPlannerDraftStore::class)->get($this->admin, $id);
    }

    private function downloadBytes(mixed $component): string
    {
        return base64_decode($component->effects['download']['content'], true);
    }

    /** Every training, attendance and personnel table except the planner's own drafts and send records. */
    private function domainHashes(): array
    {
        $hashes = [];
        foreach (Schema::getTableListing() as $table) {
            if (preg_match('/^(?:training_|employees$|employee_bid_assignments$)|vacation/i', $table) && ! str_contains($table, 'crew_plan')) {
                $rows = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
                $hashes[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
            }
        }

        return $hashes;
    }
}
