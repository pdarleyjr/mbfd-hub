<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingModule;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingCourseModuleService;
use App\Services\Training\TrainingRosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TrainingModularKioskTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private TrainingCourse $course;

    private TrainingModule $module;

    private TrainingRosterService $rosters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now('America/New_York')->setDate(2026, 10, 20)->setTime(10, 0));
        $this->actor = User::factory()->create(['account_status' => AccountStatus::Active]);
        Role::findOrCreate('training_admin', 'web');
        $this->actor->assignRole('training_admin');
        $this->rosters = app(TrainingRosterService::class);
        $this->course = $this->rosters->createCourse(['name' => 'Synthetic kiosk course', 'classifications' => ['EMS Training']], $this->actor);
        $this->module = app(TrainingCourseModuleService::class)->createModule($this->course, ['name' => 'Module 1'], $this->actor);
    }

    public function test_modular_start_requires_module_selection_and_reuses_staff_created_roster(): void
    {
        $session = $this->createOpenSession($this->module);
        $this->postJson($this->url('/courses/'.$this->course->id.'/start'))->assertUnprocessable()->assertJsonValidationErrors('training_module_id');
        $this->postJson($this->url('/courses/'.$this->course->id.'/start'), ['training_module_id' => $this->module->id])->assertOk()->assertJsonPath('url', '/r/'.$session->public_id);
        $this->postJson($this->url('/courses/'.$this->course->id.'/start'), ['session_public_id' => $session->public_id, 'training_module_id' => $this->module->id])->assertOk()->assertJsonPath('url', '/r/'.$session->public_id);
        self::assertSame(1, $this->course->sessions()->count());
    }

    public function test_existing_module_creates_today_roster_and_retries_reuse_it(): void
    {
        $payload = ['training_module_id' => $this->module->id];
        $path = $this->url('/courses/'.$this->course->id.'/start');
        $first = $this->postJson($path, $payload)->assertOk()->json('url');
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('url', $first);
        $session = $this->course->sessions()->sole();
        self::assertSame($this->module->id, $session->training_module_id);
        self::assertSame('2026-10-20', $session->held_on->toDateString());
        self::assertSame('open', $session->status);
        self::assertSame(1, $session->versions()->count());
        self::assertSame(1, $session->audits()->where('action', 'session.public_started')->count());
        $this->getJson($this->url($first.'/state'))->assertOk()->assertJsonPath('module_name', 'Module 1');
        self::assertFalse(Auth::guard('web')->check());
    }

    public function test_new_today_roster_preserves_past_completed_paper_and_other_module_sessions(): void
    {
        $past = $this->createOpenSession($this->module);
        $past->update(['held_on' => '2026-10-19']);
        $completed = $this->createOpenSession($this->module);
        $completed->update(['status' => 'submitted']);
        $paper = $this->createOpenSession($this->module);
        $paper->update(['attendance_source' => 'paper']);
        $otherModule = app(TrainingCourseModuleService::class)->createModule($this->course, ['name' => 'Module 2'], $this->actor);
        $other = $this->createOpenSession($otherModule);
        $saved = collect([$past, $completed, $paper, $other])->mapWithKeys(fn (TrainingSession $session) => [$session->id => [$session->toArray(), $session->versions()->get()->toArray()]]);
        $first = $this->rosters->startPublicSession($this->course, null, $this->module->id);
        self::assertSame(5, $this->course->sessions()->count());
        self::assertSame('2026-10-20', $first->held_on->toDateString());
        self::assertSame(0, $first->attendances()->count());
        foreach ($saved as $id => $evidence) {
            $session = TrainingSession::query()->findOrFail($id);
            self::assertSame($evidence, [$session->toArray(), $session->versions()->get()->toArray()]);
        }
        $this->getJson($this->url('/catalog'))->assertOk()->assertDontSee($completed->public_id)->assertDontSee($paper->public_id);
    }

    public function test_automatic_start_rejects_ambiguous_deliveries_without_creating_another(): void
    {
        $this->createOpenSession($this->module);
        $this->createOpenSession($this->module);
        $this->postJson($this->url('/courses/'.$this->course->id.'/start'), ['training_module_id' => $this->module->id])
            ->assertUnprocessable()->assertJsonValidationErrors('session');
        self::assertSame(2, $this->course->sessions()->count());
    }

    public function test_automatic_start_rejects_module_from_another_course(): void
    {
        $other = $this->rosters->createCourse(['name' => 'Other synthetic course', 'classifications' => ['EMS Training']], $this->actor);
        $this->postJson($this->url('/courses/'.$other->id.'/start'), ['training_module_id' => $this->module->id])
            ->assertUnprocessable()->assertJsonValidationErrors('training_module_id');
        self::assertSame(0, TrainingSession::query()->count());
    }

    public function test_authorized_new_module_creates_and_opens_roster_without_logging_kiosk_in(): void
    {
        $payload = $this->newModulePayload();
        $path = $this->url('/courses/'.$this->course->id.'/start');
        $url = $this->postJson($path, $payload)->assertOk()->json('url');
        $this->postJson($path, array_replace($payload, ['new_module_name' => '  module 2  ']))->assertOk()->assertJsonPath('url', $url);
        $module = $this->course->modules()->where('name', 'Module 2')->sole();
        self::assertSame($this->actor->id, $module->created_by);
        self::assertSame(2, $this->course->modules()->count());
        self::assertSame(1, $module->sessions()->count());
        $this->getJson($this->url($url.'/state'))->assertOk()->assertJsonPath('module_name', 'Module 2');
        self::assertFalse(Auth::guard('web')->check());
        self::assertArrayNotHasKey('password', session()->all());
        $this->getJson($this->url('/catalog'))->assertOk()->assertJsonCount(2, 'courses.0.modules');
    }

    public function test_new_module_requires_valid_credentials_and_both_existing_permissions(): void
    {
        $payload = $this->newModulePayload();
        $path = $this->url('/courses/'.$this->course->id.'/start');
        $this->postJson($path, ['new_module_name' => 'Module 2'])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->postJson($path, array_replace($payload, ['password' => 'wrong']))->assertUnprocessable()->assertJsonValidationErrors('credentials');
        $this->actor->removeRole('super_admin');
        $this->postJson($path, $payload)->assertUnprocessable()->assertJsonValidationErrors('credentials');
        foreach (['admin.access', 'training.rosters.submit'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->actor->givePermissionTo($permission);
        }
        $this->actor->removeRole('training_admin');
        $this->postJson($path, $payload)->assertForbidden();
        self::assertSame(1, $this->course->modules()->count());
        self::assertSame(0, $this->course->sessions()->count());
        self::assertFalse(Auth::guard('web')->check());
    }

    public function test_new_module_request_cannot_mix_existing_selection_or_flash_credentials(): void
    {
        $payload = $this->newModulePayload();
        $path = $this->url('/courses/'.$this->course->id.'/start');
        $this->postJson($path, $payload + ['training_module_id' => $this->module->id])->assertUnprocessable()->assertJsonValidationErrors('training_module_id');
        $this->postJson($path, $payload + ['session_public_id' => (string) Str::ulid()])->assertUnprocessable()->assertJsonValidationErrors('session_public_id');
        $this->post($path, array_replace($payload, ['new_module_name' => '   ']), ['Accept' => 'text/html'])
            ->assertUnprocessable()->assertSessionMissing('_old_input');
        self::assertSame(1, $this->course->modules()->count());
        self::assertSame(0, $this->course->sessions()->count());
    }

    public function test_new_module_can_be_added_to_a_course_without_modules(): void
    {
        $payload = $this->newModulePayload();
        $other = $this->rosters->createCourse(['name' => 'First module course', 'classifications' => ['EMS Training']], $this->actor);
        $this->postJson($this->url('/courses/'.$other->id.'/start'), $payload)->assertOk();
        self::assertSame(1, $other->modules()->count());
        self::assertSame($other->modules()->sole()->id, $other->sessions()->sole()->training_module_id);
    }

    public function test_course_without_modules_reuses_staff_roster_and_can_start_after_completion(): void
    {
        $other = $this->rosters->createCourse(['name' => 'Non modular course', 'classifications' => ['EMS Training']], $this->actor);
        $staffRoster = $this->rosters->createSession($other, ['held_on' => '2026-10-20'], $this->actor);
        $staffRoster = $this->rosters->transition($staffRoster, 'open', $this->actor);
        self::assertSame($staffRoster->id, $this->rosters->startPublicSession($other)->id);
        $staffRoster->update(['status' => 'submitted']);
        $next = $this->rosters->startPublicSession($other);
        self::assertNotSame($staffRoster->id, $next->id);
        self::assertNull($next->training_module_id);
        self::assertSame('submitted', $staffRoster->fresh()->status);
    }

    public function test_existing_unassigned_roster_stays_explicitly_accessible_after_adding_modules(): void
    {
        $unassigned = $this->rosters->createSession($this->course, ['held_on' => '2026-10-20'], $this->actor);
        $unassigned = $this->rosters->transition($unassigned, 'open', $this->actor);
        $this->postJson($this->url('/courses/'.$this->course->id.'/start'), ['session_public_id' => $unassigned->public_id])
            ->assertOk()->assertJsonPath('url', '/r/'.$unassigned->public_id);
        self::assertNull($unassigned->fresh()->training_module_id);
    }

    public function test_explicit_start_never_crosses_course_module_or_date(): void
    {
        $session = $this->createOpenSession($this->module);
        $otherModule = app(TrainingCourseModuleService::class)->createModule($this->course, ['name' => 'Module 2'], $this->actor);
        $path = '/courses/'.$this->course->id.'/start';
        $this->postJson($this->url($path), ['session_public_id' => $session->public_id, 'training_module_id' => $otherModule->id])->assertUnprocessable();
        $session->update(['held_on' => '2026-10-19']);
        $this->postJson($this->url($path), ['session_public_id' => $session->public_id])->assertUnprocessable();
        $other = $this->rosters->createCourse(['name' => 'Other synthetic course', 'classifications' => ['EMS Training']], $this->actor);
        $this->postJson($this->url('/courses/'.$other->id.'/start'), ['session_public_id' => $session->public_id])->assertUnprocessable();
    }

    public function test_catalog_distinguishes_same_day_modules_and_deliveries_without_paper_evidence(): void
    {
        $first = $this->createOpenSession($this->module, '2026-10-20T09:00:00-04:00');
        $second = $this->createOpenSession($this->module, '2026-10-20T13:00:00-04:00');
        $paper = $this->createOpenSession($this->module);
        $paper->update(['status' => 'paper', 'attendance_source' => 'paper']);
        $response = $this->getJson($this->url('/catalog'))->assertOk()
            ->assertJsonPath('courses.0.requires_session_selection', true)
            ->assertJsonCount(2, 'courses.0.sessions')
            ->assertJsonPath('courses.0.sessions.0.module_name', 'Module 1')
            ->assertJsonPath('courses.0.sessions.0.staff_managed', true);
        self::assertSame([$first->public_id, $second->public_id], array_column($response->json('courses.0.sessions'), 'public_id'));
        $response->assertDontSee($paper->public_id)->assertDontSee($this->actor->email);
    }

    public function test_paper_session_cannot_be_opened_or_signed_even_if_its_status_is_accidentally_open(): void
    {
        $paper = $this->createOpenSession($this->module);
        $paper->update(['attendance_source' => 'paper']);
        $this->postJson($this->url('/r/'.$paper->public_id.'/open'))->assertForbidden();
        $this->expectException(ValidationException::class);
        $this->rosters->sign($paper, 1, 'not-a-signature', (string) Str::uuid());
    }

    public function test_distinct_same_day_sessions_recover_only_the_selected_session(): void
    {
        $first = $this->createOpenSession($this->module);
        $second = $this->createOpenSession($this->module);
        foreach ([$first, $second, $first] as $session) {
            self::assertSame($session->id, $this->rosters->startPublicSession($this->course, $session->public_id, $this->module->id)->id);
        }
        self::assertSame(2, $this->course->sessions()->count());
        self::assertSame(0, $second->attendances()->count());
    }

    private function createOpenSession(TrainingModule $module, ?string $startsAt = null): TrainingSession
    {
        $session = $this->rosters->createSession($this->course, ['held_on' => '2026-10-20', 'starts_at' => $startsAt, 'training_module_id' => $module->id], $this->actor);

        return $this->rosters->transition($session, 'open', $this->actor);
    }

    private function newModulePayload(): array
    {
        config(['identity.local_login_enabled' => true]);
        $employee = Employee::query()->create(['employee_id' => 'MODULE-QA-1', 'name' => 'Synthetic Module Admin', 'roster_status' => 'active', 'must_change_password' => false]);
        $this->actor->update(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id, 'must_change_password' => false, 'password' => Hash::make('synthetic-module-password')]);
        Role::findOrCreate('super_admin', 'web');
        $this->actor->assignRole('super_admin');

        return ['new_module_name' => 'Module 2', 'email' => $employee->employee_id, 'password' => 'synthetic-module-password'];
    }

    private function url(string $path): string
    {
        return 'https://rosters.mbfdhub.com'.$path;
    }
}
