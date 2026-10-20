<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\AccountStatus;
use App\Models\Training\TrainingCourse;
use App\Models\User;
use App\Services\Training\TrainingCourseModuleService;
use App\Services\Training\TrainingRosterService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TrainingCourseModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private TrainingRosterService $rosters;

    private TrainingCourseModuleService $modules;

    private TrainingCourse $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['account_status' => AccountStatus::Active]);
        Role::findOrCreate('training_admin', 'web');
        $this->actor->assignRole('training_admin');
        $this->rosters = app(TrainingRosterService::class);
        $this->modules = app(TrainingCourseModuleService::class);
        $this->course = $this->rosters->createCourse(['name' => 'Synthetic modular course', 'classifications' => ['EMS Training']], $this->actor);
    }

    public function test_tracking_is_optional_and_reversible_without_losing_sessions(): void
    {
        self::assertFalse($this->course->fresh()->track_attendance_matrix);
        $session = $this->rosters->createSession($this->course, ['held_on' => '2026-10-20'], $this->actor);
        foreach ([true, false, true] as $enabled) {
            $course = $this->rosters->updateCourse($this->course, ['name' => $this->course->name, 'classifications' => ['EMS Training'], 'track_attendance_matrix' => $enabled], $this->actor);
            self::assertSame($enabled, $course->track_attendance_matrix);
            self::assertSame($session->id, $course->sessions()->sole()->id);
        }
        self::assertSame(3, DB::table('training_roster_audits')->where('action', 'course.updated')->count());
    }

    public function test_two_deliveries_on_one_day_keep_separate_rosters_and_module(): void
    {
        $module = $this->modules->createModule($this->course, ['name' => 'Module 1', 'audience' => ['ranks' => ['Firefighter'], 'shifts' => ['B Shift']]], $this->actor);
        $sessions = [];
        foreach (['2026-10-20T09:00:00-04:00', '2026-10-20T13:00:00-04:00'] as $time) {
            $sessions[] = $this->rosters->createSession($this->course, ['training_module_id' => $module->id, 'held_on' => '2026-10-20', 'starts_at' => $time], $this->actor);
        }
        self::assertNotSame($sessions[0]->id, $sessions[1]->id);
        self::assertSame($module->id, $sessions[0]->training_module_id);
        self::assertSame('2026-10-20T13:00:00+00:00', $sessions[0]->fresh()->starts_at->toIso8601String());
        self::assertSame(0, $sessions[1]->attendances()->count());
        self::assertTrue($module->fresh()->is_required);
    }

    public function test_module_from_another_course_is_rejected_by_service_and_database(): void
    {
        $other = $this->rosters->createCourse(['name' => 'Other synthetic course', 'classifications' => ['EMS Training']], $this->actor);
        $module = $this->modules->createModule($other, ['name' => 'Other module'], $this->actor);
        try {
            $this->rosters->createSession($this->course, ['training_module_id' => $module->id, 'held_on' => '2026-10-20'], $this->actor);
            self::fail('A cross-course module was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('training_module_id', $exception->errors());
        }
        $session = $this->rosters->createSession($this->course, ['held_on' => '2026-10-20'], $this->actor);
        $this->expectException(QueryException::class);
        DB::table('training_sessions')->where('id', $session->id)->update(['training_module_id' => $module->id]);
    }

    public function test_mapping_a_finalized_roster_is_audited_and_preserves_immutable_versions(): void
    {
        $session = $this->rosters->createSession($this->course, ['held_on' => '2026-10-20'], $this->actor);
        $version = $session->versions()->sole();
        $snapshot = $version->snapshot;
        $session->update(['status' => 'submitted']);
        $module = $this->modules->createModule($this->course, ['name' => 'Module 1'], $this->actor);
        $mapped = $this->modules->mapSession($session, $module, $this->actor, 'Reviewed existing course attribution', 1, null);
        self::assertSame($module->id, $mapped->training_module_id);
        self::assertSame(1, $mapped->revision);
        self::assertSame('submitted', $mapped->status);
        self::assertSame($snapshot, $version->fresh()->snapshot);
        self::assertSame(1, $mapped->versions()->count());
        self::assertSame(1, $mapped->audits()->where('action', 'session.module_mapped')->count());
        $this->expectException(ValidationException::class);
        $this->modules->mapSession($session, null, $this->actor, 'Stale module attribution', 1, null);
    }

    public function test_regular_session_edit_cannot_bypass_mapping_audit(): void
    {
        $module = $this->modules->createModule($this->course, ['name' => 'Module 1'], $this->actor);
        $session = $this->rosters->createSession($this->course, ['held_on' => '2026-10-20'], $this->actor);
        $this->expectException(ValidationException::class);
        $this->rosters->updateSession($session, ['held_on' => '2026-10-20', 'training_module_id' => $module->id], $this->actor);
    }

    public function test_viewer_cannot_manage_modules_or_map_sessions(): void
    {
        $viewer = User::factory()->create(['account_status' => AccountStatus::Active]);
        Role::findOrCreate('training_viewer', 'web');
        $viewer->assignRole('training_viewer');
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->modules->createModule($this->course, ['name' => 'Unauthorized module'], $viewer);
    }

    public function test_audience_rejects_unsupported_dimensions_and_values(): void
    {
        $this->expectException(ValidationException::class);
        $this->modules->createModule($this->course, ['name' => 'Invalid audience', 'audience' => ['certified' => true, 'shifts' => ['unknown']]], $this->actor);
    }

    public function test_mapping_rejects_a_stale_digital_revision(): void
    {
        $session = $this->rosters->createSession($this->course, ['held_on' => '2026-10-20'], $this->actor);
        $module = $this->modules->createModule($this->course, ['name' => 'Module 1'], $this->actor);
        $this->rosters->transition($session, 'open', $this->actor);
        $this->expectException(ValidationException::class);
        $this->modules->mapSession($session, $module, $this->actor, 'Stale digital revision', 1, null);
    }

    public function test_viewer_cannot_map_a_history_bearing_session(): void
    {
        $session = $this->rosters->createSession($this->course, ['held_on' => '2026-10-20'], $this->actor);
        $session->update(['status' => 'certified']);
        $viewer = User::factory()->create(['account_status' => AccountStatus::Active]);
        Role::findOrCreate('training_viewer', 'web');
        $viewer->assignRole('training_viewer');
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->modules->mapSession($session, null, $viewer, 'Unauthorized attribution', 1, null);
    }
}
