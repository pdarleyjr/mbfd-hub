<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Filament\Training\Resources\TrainingSessionResource\Pages\CreateTrainingSession;
use App\Filament\Training\Resources\TrainingSessionResource\Pages\ViewTrainingSession;
use App\Models\Employee;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingRosterAudit;
use App\Models\Training\TrainingRosterVersion;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingRosterService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TrainingRosterAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        foreach (['training_admin', 'training_viewer', 'super_admin', 'admin'] as $name) {
            Role::findOrCreate($name, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('training'));
    }

    public function test_viewer_can_view_and_export_but_cannot_mutate_or_create(): void
    {
        $session = $this->rosterSession();
        $viewer = $this->user('training_viewer');
        $gate = Gate::forUser($viewer);
        $this->assertTrue($gate->allows('view', $session));
        $this->assertTrue($gate->allows('export', $session));
        foreach (['update', 'open', 'close', 'correct', 'certify', 'archive', 'launch', 'manageKiosk', 'delete'] as $ability) {
            $this->assertFalse($gate->allows($ability, $session), $ability);
        }
        $this->assertFalse($gate->allows('create', TrainingSession::class));
        $this->actingAs($viewer)->get('/training/training-rosters')->assertOk()->assertSee('Download PDF')->assertSee('Download Excel')->assertDontSee('New session');
        Livewire::test(CreateTrainingSession::class)->assertForbidden();
        Livewire::test(ViewTrainingSession::class, ['record' => $session->id])
            ->assertActionHidden('open')->assertActionHidden('editSession')->assertActionHidden('addException');
    }

    public function test_general_admin_does_not_gain_roster_permission_from_admin_panel_access(): void
    {
        $session = $this->rosterSession();
        $user = $this->user('admin');
        $user->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', TrainingSession::class));
        $this->assertFalse(Gate::forUser($user)->allows('export', $session));
    }

    public function test_super_admin_retention_and_lifecycle_rules_are_not_bypassed(): void
    {
        $session = $this->rosterSession();
        $user = $this->user('super_admin');
        $gate = Gate::forUser($user);
        $this->assertFalse($gate->allows('delete', $session));
        $this->assertFalse($gate->allows('forceDelete', $session));
        $session->forceFill(['status' => 'archived'])->save();
        $this->assertFalse($gate->allows('update', $session));
        $this->assertFalse($gate->allows('correct', $session));
        $this->assertTrue($gate->allows('export', $session));
        $user->forceFill(['account_status' => AccountStatus::Disabled])->save();
        $this->assertFalse($gate->allows('export', $session));
    }

    public function test_training_admin_pages_render_and_create_session_through_audited_service(): void
    {
        $admin = $this->user('training_admin');
        $course = app(TrainingRosterService::class)->createCourse(['name' => 'Synthetic test course', 'classifications' => ['EMS Training']], $admin);
        $this->actingAs($admin);
        $this->get('/training/training-courses')->assertOk()->assertSee('Synthetic test course');
        Livewire::test(CreateTrainingSession::class)->fillForm([
            'training_course_id' => $course->id, 'course_name' => $course->name,
            'classifications' => ['EMS Training'], 'held_on' => '2026-10-15',
        ])->call('create')->assertHasNoFormErrors();
        $session = TrainingSession::query()->sole();
        $this->assertSame('draft', $session->status);
        $this->assertDatabaseHas('training_roster_audits', ['training_session_id' => $session->id, 'action' => 'session.created', 'actor_user_id' => $admin->id]);
        $this->get('/training/training-rosters/'.$session->id)->assertOk()->assertSee('Download PDF')->assertSee('Download Excel')->assertSee('Saved roster versions');
    }

    public function test_employee_history_is_scoped_to_current_employee_and_excludes_voided_attendance(): void
    {
        $session = $this->rosterSession();
        $employee = Employee::query()->create(['employee_id' => 'TRAINING-TEST-01', 'name' => 'Synthetic Own Employee', 'rank' => 'Firefighter', 'roster_status' => 'active']);
        $other = Employee::query()->create(['employee_id' => 'TRAINING-TEST-02', 'name' => 'Synthetic Other Employee', 'rank' => 'Firefighter', 'roster_status' => 'active']);
        $this->attendance($session, $employee);
        $this->attendance($session, $other);
        $voidedSession = $this->rosterSession('Synthetic Voided Course');
        $this->attendance($voidedSession, $employee, true);
        $otherSession = $this->rosterSession('Synthetic Other Only Course');
        $this->attendance($otherSession, $other);
        $this->actingAs($employee, 'employee')->get('/employee/training-history')
            ->assertOk()->assertSee('Synthetic Test Course')->assertDontSee('Synthetic Voided Course')->assertDontSee('Synthetic Other Only Course')
            ->assertDontSee('Download Excel')->assertDontSee('private-signature-test.png');
    }

    public function test_session_actions_preserve_the_opened_form_revision_and_reject_a_stale_save(): void
    {
        $session = $this->rosterSession();
        $this->actingAs($this->user('training_admin'));
        $page = Livewire::test(ViewTrainingSession::class, ['record' => $session->id])->mountAction('open');
        $session->increment('revision');
        $page->callMountedAction()->assertHasActionErrors(['expected_revision']);
        $this->assertSame('draft', $session->fresh()->status);

        Livewire::test(ViewTrainingSession::class, ['record' => $session->id])
            ->callAction('open')->assertHasNoActionErrors();
        $this->assertSame('open', $session->fresh()->status);
        Livewire::test(ViewTrainingSession::class, ['record' => $session->id])
            ->callAction('close')->assertHasNoActionErrors();
        $this->assertSame('closed', $session->fresh()->status);
    }

    public function test_history_distinguishes_corrected_attendance_and_certification_after_archival(): void
    {
        $session = $this->rosterSession();
        $employee = Employee::query()->create(['employee_id' => 'TRAINING-TEST-HISTORY', 'name' => 'Synthetic History Employee', 'rank' => 'Captain', 'roster_status' => 'active']);
        $attendance = $this->attendance($session, $employee);
        TrainingRosterAudit::query()->create([
            'training_session_id' => $session->id, 'training_attendance_id' => $attendance->id,
            'action' => 'attendance.corrected', 'reason' => 'Synthetic correction fixture',
            'before' => ['rank' => 'Firefighter'], 'after' => ['rank' => 'Captain'], 'created_at' => now(),
        ]);
        $session->forceFill(['revision' => 2, 'status' => 'archived'])->save();
        TrainingRosterVersion::query()->create([
            'training_session_id' => $session->id, 'revision' => 2,
            'snapshot' => ['schema_version' => 1], 'certified_at' => now(),
        ]);
        $this->actingAs($employee, 'employee')->get('/employee/training-history')
            ->assertOk()->assertSee('Training Division Classes Attended')
            ->assertSee('Attendance corrected')->assertSee('Roster certified');
    }

    public function test_admin_actions_record_exception_correct_void_reinstate_and_certify(): void
    {
        Storage::fake('local');
        $employee = Employee::query()->create(['employee_id' => 'TRAINING-ACTION-01', 'name' => 'Synthetic Officer', 'rank' => 'Captain', 'roster_status' => 'active']);
        $admin = $this->user('training_admin');
        $admin->forceFill(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id])->save();
        $session = $this->rosterSession();
        app(TrainingRosterService::class)->transition($session, 'open', $admin);
        $this->actingAs($admin);
        $exception = [
            'employee_profile_id' => $employee->id, 'first_name' => 'Synthetic', 'last_name' => 'Officer',
            'signature' => $this->signature(), 'reason' => 'Synthetic unavailable assignment test',
        ];
        Livewire::test(ViewTrainingSession::class, ['record' => $session->id])
            ->callAction('addException', $exception)->assertHasNoActionErrors();
        $attendance = TrainingAttendance::query()->sole();
        Livewire::test(ViewTrainingSession::class, ['record' => $session->id])
            ->callTableAction('correct', $attendance, ['employee_profile_id' => $employee->id, 'signature' => $this->signature(), 'reason' => 'Synthetic signature correction test'])
            ->assertHasNoTableActionErrors();
        Livewire::test(ViewTrainingSession::class, ['record' => $session->id])
            ->callTableAction('void', $attendance, ['reason' => 'Synthetic mistaken attendance test'])
            ->assertHasNoTableActionErrors();
        $this->assertNotNull($attendance->fresh()->voided_at);
        Livewire::test(ViewTrainingSession::class, ['record' => $session->id])
            ->callAction('addException', $exception)->assertHasNoActionErrors();
        $this->assertNull($attendance->fresh()->voided_at);
        $this->assertDatabaseCount('training_attendances', 1);
        Livewire::test(ViewTrainingSession::class, ['record' => $session->id])
            ->callAction('close')->assertHasNoActionErrors();
        Livewire::test(ViewTrainingSession::class, ['record' => $session->id])
            ->callAction('certify', ['signature' => $this->signature()])->assertHasNoActionErrors();
        $this->assertSame('certified', $session->fresh()->status);
        Livewire::test(ViewTrainingSession::class, ['record' => $session->id])
            ->callAction('archive', ['reason' => 'Synthetic completed class test'])->assertHasNoActionErrors();
        $this->assertSame('archived', $session->fresh()->status);
        $this->assertNotNull($session->fresh()->latestVersion->certified_at);
    }

    public function test_training_history_guest_redirects_to_canonical_login(): void
    {
        $this->get('/employee/training-history')->assertRedirect('/login');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'must_change_password' => false]);
        $user->assignRole($role);

        return $user;
    }

    private function rosterSession(string $name = 'Synthetic Test Course'): TrainingSession
    {
        $actor = $this->user('training_admin');
        $service = app(TrainingRosterService::class);
        $course = $service->createCourse(['name' => $name, 'classifications' => ['EMS Training']], $actor);

        return $service->createSession($course, ['held_on' => '2026-10-15'], $actor);
    }

    private function attendance(TrainingSession $session, Employee $employee, bool $voided = false): TrainingAttendance
    {
        return TrainingAttendance::query()->create([
            'training_session_id' => $session->id, 'employee_profile_id' => $employee->id,
            'snapshot' => ['employee_id' => $employee->employee_id, 'display_name' => $employee->name],
            'signature' => ['disk' => 'private', 'path' => 'private-signature-test.png', 'sha256' => str_repeat('0', 64), 'mime' => 'image/png'],
            'signed_at' => now(), 'voided_at' => $voided ? now() : null,
            'client_submission_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', $employee->employee_id),
        ]);
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(500, 180);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 10, 20, 40);
        imagesetthickness($image, 5);
        for ($x = 30; $x < 450; $x += 30) {
            imageline($image, $x, 130, $x + 20, 40, $ink);
        }
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }
}
