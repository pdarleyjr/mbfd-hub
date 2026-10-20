<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\AccountStatus;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingModule;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingCourseModuleService;
use App\Services\Training\TrainingRosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public function test_modular_start_requires_explicit_session_and_never_creates_a_roster(): void
    {
        $session = $this->session($this->module);
        $this->postJson($this->url('/courses/'.$this->course->id.'/start'))->assertUnprocessable()->assertJsonValidationErrors('session');
        $this->postJson($this->url('/courses/'.$this->course->id.'/start'), ['session_public_id' => $session->public_id, 'training_module_id' => $this->module->id])->assertOk()->assertJsonPath('url', '/r/'.$session->public_id);
        self::assertSame(1, $this->course->sessions()->count());
    }

    public function test_explicit_start_never_crosses_course_module_or_date(): void
    {
        $session = $this->session($this->module);
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
        $first = $this->session($this->module, '2026-10-20T09:00:00-04:00');
        $second = $this->session($this->module, '2026-10-20T13:00:00-04:00');
        $paper = $this->session($this->module);
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
        $paper = $this->session($this->module);
        $paper->update(['attendance_source' => 'paper']);
        $this->postJson($this->url('/r/'.$paper->public_id.'/open'))->assertForbidden();
        $this->expectException(ValidationException::class);
        $this->rosters->sign($paper, 1, 'not-a-signature', (string) Str::uuid());
    }

    public function test_distinct_same_day_sessions_recover_only_the_selected_session(): void
    {
        $first = $this->session($this->module);
        $second = $this->session($this->module);
        foreach ([$first, $second, $first] as $session) {
            self::assertSame($session->id, $this->rosters->startPublicSession($this->course, $session->public_id, $this->module->id)->id);
        }
        self::assertSame(2, $this->course->sessions()->count());
        self::assertSame(0, $second->attendances()->count());
    }

    private function session(TrainingModule $module, ?string $startsAt = null): TrainingSession
    {
        $session = $this->rosters->createSession($this->course, ['held_on' => '2026-10-20', 'starts_at' => $startsAt, 'training_module_id' => $module->id], $this->actor);

        return $this->rosters->transition($session, 'open', $this->actor);
    }

    private function url(string $path): string
    {
        return 'https://rosters.mbfdhub.com'.$path;
    }
}
