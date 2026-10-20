<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\AccountStatus;
use App\Filament\Training\Resources\TrainingCourseResource\Pages\CreateTrainingCourse;
use App\Filament\Training\Resources\TrainingCourseResource\Pages\EditTrainingCourse;
use App\Filament\Training\Resources\TrainingCourseResource\Pages\ManageTrainingModules;
use App\Filament\Training\Resources\TrainingCourseResource\Pages\ReviewTrainingPaper;
use App\Filament\Training\Resources\TrainingCourseResource\Pages\ViewTrainingMatrix;
use App\Filament\Training\Resources\TrainingSessionResource\Pages\ListTrainingSessions;
use App\Filament\Training\Resources\TrainingSessionResource\Pages\ViewTrainingSession;
use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingPaperAttendance;
use App\Models\Training\TrainingPaperRosterImport;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingCourseModuleService;
use App\Services\Training\TrainingRosterService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TrainingMatrixAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        foreach (['training_admin', 'training_viewer', 'super_admin', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('training'));
    }

    public function test_course_toggle_is_available_on_create_and_edit_without_losing_modules(): void
    {
        $admin = $this->user('training_admin');
        $this->actingAs($admin);
        Livewire::test(CreateTrainingCourse::class)->fillForm([
            'name' => 'Synthetic modular course', 'classifications' => ['Driver Training'], 'track_attendance_matrix' => true,
        ])->call('create')->assertHasNoFormErrors();
        $course = TrainingCourse::query()->sole();
        $module = app(TrainingCourseModuleService::class)->createModule($course, ['name' => 'Module 1'], $admin);
        Livewire::test(EditTrainingCourse::class, ['record' => $course->id])->fillForm(['track_attendance_matrix' => false])->call('save')->assertHasNoFormErrors();
        $this->assertFalse($course->fresh()->track_attendance_matrix);
        $this->assertModelExists($module);
        $this->get('/training/training-courses/'.$course->id.'/matrix')->assertNotFound();
        Livewire::test(EditTrainingCourse::class, ['record' => $course->id])->fillForm(['track_attendance_matrix' => true])->call('save')->assertHasNoFormErrors();
        $this->get('/training/training-courses/'.$course->id.'/matrix')->assertOk()->assertSee('Module summary');
    }

    public function test_viewer_reads_matrix_but_cannot_manage_modules_and_unrelated_admin_is_denied(): void
    {
        $admin = $this->user('training_admin');
        $course = $this->course($admin);
        $this->actingAs($this->user('training_viewer'));
        Livewire::test(ManageTrainingModules::class, ['record' => $course->id])->assertActionHidden('createModule');
        Livewire::test(ViewTrainingMatrix::class, ['record' => $course->id])->assertOk()->assertSee('Session detail');
        $this->actingAs($this->user('admin'))->get('/training/training-courses/'.$course->id.'/matrix')->assertForbidden();
    }

    public function test_rendered_open_matrix_revokes_access_when_tracking_is_disabled(): void
    {
        $admin = $this->user('training_admin');
        $course = $this->course($admin);
        $this->actingAs($admin);
        $page = Livewire::test(ViewTrainingMatrix::class, ['record' => $course->id])->assertOk();
        $course->update(['track_attendance_matrix' => false]);
        $page->call('download', false)->assertNotFound();
    }

    public function test_session_module_mapping_and_next_session_do_not_copy_attendance(): void
    {
        $admin = $this->user('training_admin');
        $course = $this->course($admin);
        $module = app(TrainingCourseModuleService::class)->createModule($course, ['name' => 'Synthetic module'], $admin);
        $session = app(TrainingRosterService::class)->createSession($course, ['held_on' => '2026-10-20', 'course_name' => 'Synthetic delivery', 'classifications' => ['Driver Training']], $admin);
        $this->actingAs($admin);
        Livewire::test(ViewTrainingSession::class, ['record' => $session->id])->callAction('mapModule', [
            'training_module_id' => $module->id, 'expected_module_id' => null, 'expected_revision' => $session->revision, 'reason' => 'Reviewed reporting attribution',
        ])->assertHasNoActionErrors();
        $this->assertSame($module->id, $session->fresh()->training_module_id);
        Livewire::test(ViewTrainingSession::class, ['record' => $session->id])->callAction('nextSession', [
            'course_name' => 'Synthetic next delivery', 'held_on' => '2026-10-21', 'classifications' => ['Driver Training'],
        ])->assertHasNoActionErrors();
        $next = TrainingSession::query()->whereKeyNot($session->id)->sole();
        $this->assertSame($module->id, $next->training_module_id);
        $this->assertSame(0, $next->attendances()->count());
        $this->assertSame('draft', $next->status);
    }

    public function test_paper_session_has_source_review_and_no_digital_lifecycle_even_for_super_admin(): void
    {
        $admin = $this->user('super_admin');
        $course = $this->course($admin);
        $session = app(TrainingRosterService::class)->createSession($course, ['held_on' => '2026-10-13'], $admin);
        $session->update(['attendance_source' => 'paper', 'status' => 'paper']);
        $this->actingAs($admin);
        foreach (['open', 'close', 'reopen', 'correct', 'certify', 'launch', 'manageKiosk', 'update'] as $ability) {
            $this->assertFalse(Gate::allows($ability, $session), $ability);
        }
        Livewire::test(ViewTrainingSession::class, ['record' => $session->id])->assertActionHidden('pdf')->assertActionHidden('xlsx')
            ->assertActionHidden('launch')->assertActionHidden('certify')->assertSee('Historical paper evidence');
    }

    public function test_paper_review_ui_is_role_scoped_and_employee_history_shows_only_own_approved_evidence(): void
    {
        $admin = $this->user('training_admin');
        $course = $this->course($admin);
        $session = app(TrainingRosterService::class)->createSession($course, ['held_on' => '2026-10-13'], $admin);
        $session->update(['attendance_source' => 'paper', 'status' => 'paper']);
        $own = Employee::query()->create(['employee_id' => 'SYNTH-PAPER-OWN', 'name' => 'Synthetic Own Member', 'rank' => 'Firefighter', 'roster_status' => 'active']);
        $other = Employee::query()->create(['employee_id' => 'SYNTH-PAPER-OTHER', 'name' => 'Synthetic Other Member', 'rank' => 'Firefighter', 'roster_status' => 'active']);
        $import = TrainingPaperRosterImport::query()->create([
            'training_session_id' => $session->id, 'source_sha256' => str_repeat('a', 64),
            'archive_sha256' => str_repeat('b', 64), 'manifest_sha256' => str_repeat('c', 64),
            'source_filename' => 'synthetic.pdf', 'disk' => 'local', 'path' => 'training-paper/originals/'.str_repeat('a', 64).'.pdf',
            'page_count' => 1, 'imported_by' => $admin->id, 'imported_at' => now(), 'importer_version' => 'test',
        ]);
        foreach ([$own, $other] as $employee) {
            TrainingPaperAttendance::query()->create([
                'training_paper_roster_import_id' => $import->id, 'training_session_id' => $session->id,
                'employee_profile_id' => $employee->id, 'source_page' => 1, 'source_row' => (string) $employee->id,
                'raw_transcription' => 'PRIVATE SYNTHETIC TRANSCRIPTION', 'evidence_present' => true,
                'review_status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'reason' => 'Synthetic verified source fixture',
            ]);
        }
        $entry = TrainingPaperAttendance::query()->firstOrFail();
        $this->actingAs($this->user('training_viewer'));
        Livewire::test(ReviewTrainingPaper::class, ['record' => $course->id])
            ->assertOk()->assertTableActionHidden('review', $entry)->assertDontSee('PRIVATE SYNTHETIC TRANSCRIPTION');
        Livewire::test(ListTrainingSessions::class)->assertTableColumnStateSet('attendee_count', 2, $session->id);
        foreach (['pending', 'excluded', 'voided'] as $status) {
            $entry->update(['review_status' => $status]);
            Livewire::test(ListTrainingSessions::class)->assertTableColumnStateSet('attendee_count', 1, $session->id);
        }
        $entry->update(['review_status' => 'approved']);
        $this->actingAs($own, 'employee')->get('/employee/training-history')->assertOk()
            ->assertSee('Verified paper roster')->assertSee('Oct 13, 2026')
            ->assertDontSee('Synthetic Other Member')->assertDontSee('PRIVATE SYNTHETIC TRANSCRIPTION')->assertDontSee('Original PDF');
    }

    private function course(User $admin): TrainingCourse
    {
        return app(TrainingRosterService::class)->createCourse(['name' => 'Synthetic training course', 'classifications' => ['Driver Training'], 'track_attendance_matrix' => true], $admin);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'must_change_password' => false]);
        $user->assignRole($role);

        return $user;
    }
}
