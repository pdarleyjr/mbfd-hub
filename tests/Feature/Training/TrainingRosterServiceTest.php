<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingRosterAudit;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingAssignmentProvider;
use App\Services\Training\TrainingRosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TrainingRosterServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Employee $employee;

    private TrainingRosterService $service;

    private TrainingSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('filesystems.private', 'local');
        $this->employee = Employee::query()->create(['employee_id' => 'TEST-001', 'name' => 'Synthetic Training Fixture', 'rank' => 'Firefighter', 'roster_status' => 'active']);
        $this->actor = User::factory()->create(['employee_profile_id' => $this->employee->id, 'employee_id' => 'TEST-001', 'account_status' => AccountStatus::Active]);
        Gate::before(fn (User $user) => $user->id === $this->actor->id ? true : null);
        $snapshot = ['employee_profile_id' => $this->employee->id, 'employee_id' => 'TEST-001', 'first_name' => 'Synthetic', 'last_name' => 'Fixture',
            'display_name' => 'Synthetic Training Fixture', 'rank' => 'Firefighter', 'shift' => 'A Shift', 'term_label' => 'Current assignment',
            'assignment_source' => 'TELESTAFF_IMPORT', 'assignment_id' => 'synthetic-effective-assignment', 'as_of' => now('America/New_York')->toDateString()];
        $this->mock(TrainingAssignmentProvider::class)->shouldReceive('candidates')->andReturn([$snapshot]);
        $this->service = app(TrainingRosterService::class);
        $course = $this->service->createCourse(['name' => 'Synthetic training course', 'classifications' => [TrainingCourse::CLASSIFICATIONS[0]]], $this->actor);
        $this->session = $this->service->createSession($course, ['held_on' => now()->toDateString()], $this->actor);
        $this->session = $this->service->transition($this->session, 'open', $this->actor);
    }

    public function test_identical_retry_recovers_after_close_and_changed_payload_is_rejected(): void
    {
        $uuid = (string) Str::uuid();
        $signature = $this->signature();
        $saved = $this->service->sign($this->session, $this->employee->id, $signature, $uuid);
        $this->service->transition($this->session, 'closed', $this->actor);
        self::assertSame($saved->id, $this->service->sign($this->session, $this->employee->id, $signature, $uuid)->id);
        self::assertSame(1, TrainingAttendance::query()->count());
        $this->expectException(ValidationException::class);
        $this->service->sign($this->session, $this->employee->id + 1, $signature, $uuid);
    }

    public function test_different_request_id_cannot_duplicate_an_employee(): void
    {
        $this->service->sign($this->session, $this->employee->id, $this->signature(), (string) Str::uuid());
        $this->expectException(ValidationException::class);
        $this->service->sign($this->session, $this->employee->id, $this->signature(), (string) Str::uuid());
    }

    public function test_closed_roster_rejects_a_new_signature(): void
    {
        $this->service->transition($this->session, 'closed', $this->actor);
        $this->expectException(ValidationException::class);
        $this->service->sign($this->session, $this->employee->id, $this->signature(), (string) Str::uuid());
    }

    public function test_certification_retains_dataset_and_corrections_require_audited_reopen(): void
    {
        $attendance = $this->service->sign($this->session, $this->employee->id, $this->signature(), (string) Str::uuid());
        $this->session = $this->service->transition($this->session, 'closed', $this->actor);
        $certified = $this->service->certify($this->session, $this->actor, $this->employee->id, $this->signature());
        $snapshot = $certified->snapshot;
        try {
            $this->service->void($attendance, $this->actor, 'Synthetic correction');
            self::fail('Certified roster accepted a mutation.');
        } catch (ValidationException) {
            self::assertNull($attendance->fresh()->voided_at);
        }
        $this->session = $this->service->transition($this->session, 'closed', $this->actor, 'Synthetic correction review');
        $this->service->void($attendance, $this->actor, 'Synthetic mistaken identity');
        self::assertSame($snapshot, $certified->fresh()->snapshot);
        self::assertCount(1, $certified->snapshot['attendance']);
        self::assertCount(0, $this->service->snapshot($this->session)->snapshot['attendance']);
        self::assertNotNull($attendance->fresh()->voided_at);
        self::assertSame(1, TrainingRosterAudit::query()->where('action', 'attendance.voided')->count());
    }

    public function test_cannot_certify_for_another_employee(): void
    {
        $this->session = $this->service->transition($this->session, 'closed', $this->actor);
        $this->expectException(ValidationException::class);
        $this->service->certify($this->session, $this->actor, $this->employee->id + 1, $this->signature());
    }

    public function test_blank_signature_does_not_create_attendance_or_advance_revision(): void
    {
        $revision = $this->session->revision;
        try {
            $this->service->sign($this->session, $this->employee->id, $this->signature(false), (string) Str::uuid());
            self::fail('Blank signature accepted.');
        } catch (ValidationException) {
            self::assertSame(0, TrainingAttendance::query()->count());
            self::assertSame($revision, $this->session->fresh()->revision);
        }
    }

    public function test_snapshot_is_unchanged_after_personnel_and_course_edits(): void
    {
        $this->service->sign($this->session, $this->employee->id, $this->signature(), (string) Str::uuid());
        $version = $this->service->snapshot($this->session);
        $this->employee->update(['name' => 'Synthetic changed name', 'rank' => 'Captain']);
        $this->service->updateCourse($this->session->course, ['name' => 'Synthetic changed course', 'classifications' => ['EMS Training']], $this->actor);
        self::assertSame('Synthetic training course', $version->fresh()->snapshot['session']['course_name']);
        self::assertSame('Firefighter', $version->fresh()->snapshot['attendance'][0]['snapshot']['rank']);
        $this->expectException(\LogicException::class);
        $version->update(['snapshot' => []]);
    }

    public function test_supervised_exception_retains_null_assignment_and_confirmed_names(): void
    {
        $attendance = $this->service->addException($this->session, $this->employee->id, $this->signature(), $this->actor, 'Synthetic missing assignment', ['first_name' => 'Synthetic', 'last_name' => 'Fixture']);
        self::assertNull($attendance->snapshot['shift']);
        self::assertNull($attendance->snapshot['term_label']);
        self::assertNull($attendance->snapshot['assignment_id']);
        self::assertSame('instructor_confirmed', $attendance->snapshot['name_source']);
    }

    public function test_signature_correction_retains_original_attendance_date_identity_snapshot_and_receipt(): void
    {
        $this->travelTo(now()->subDays(2));
        $uuid = (string) Str::uuid();
        $signature = $this->signature();
        $attendance = $this->service->sign($this->session, $this->employee->id, $signature, $uuid);
        $snapshot = $attendance->snapshot;
        $signedAt = $attendance->signed_at->toIso8601String();
        $this->travelBack();
        $corrected = $this->service->correct($attendance, ['employee_profile_id' => $this->employee->id, 'signature' => $signature], $this->actor, 'Synthetic signature correction');
        self::assertSame($snapshot, $corrected->snapshot);
        self::assertSame($signedAt, $corrected->signed_at->toIso8601String());
        self::assertSame($uuid, $corrected->client_submission_id);
        self::assertTrue($corrected->signature_signed_at->greaterThan($corrected->signed_at));
        self::assertSame($corrected->signature_signed_at->toIso8601String(), $this->service->snapshot($this->session)->snapshot['attendance'][0]['signature_signed_at']);
        $this->expectException(ValidationException::class);
        $this->service->sign($this->session, $this->employee->id, $signature, $uuid);
    }

    public function test_stale_admin_form_cannot_overwrite_a_newer_roster(): void
    {
        $revision = $this->session->revision;
        $this->service->sign($this->session, $this->employee->id, $this->signature(), (string) Str::uuid());
        $this->expectException(ValidationException::class);
        $this->service->transition($this->session, 'closed', $this->actor, null, $revision);
    }

    public function test_unreadable_signature_storage_cannot_acknowledge_attendance(): void
    {
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnTrue();
        $disk->shouldReceive('get')->once()->andReturnNull();
        Storage::shouldReceive('disk')->twice()->with('local')->andReturn($disk);
        $revision = $this->session->revision;
        try {
            $this->service->sign($this->session, $this->employee->id, $this->signature(), (string) Str::uuid());
            self::fail('Unreadable signature was acknowledged.');
        } catch (ValidationException) {
            self::assertSame(0, TrainingAttendance::query()->count());
            self::assertSame($revision, $this->session->fresh()->revision);
        }
    }

    public function test_identity_correction_resolves_assignment_on_original_attendance_day(): void
    {
        $attendance = $this->service->sign($this->session, $this->employee->id, $this->signature(), (string) Str::uuid());
        $attendance->update(['signed_at' => '2026-09-02 02:00:00']);
        $originalReceipt = $attendance->client_submission_id;
        $replacement = Employee::query()->create(['employee_id' => 'TEST-002', 'name' => 'Synthetic Replacement', 'rank' => 'Captain', 'roster_status' => 'active']);
        $historical = array_replace($attendance->snapshot, ['employee_profile_id' => $replacement->id, 'employee_id' => 'TEST-002', 'first_name' => 'Synthetic', 'last_name' => 'Replacement', 'display_name' => $replacement->name, 'rank' => 'Firefighter', 'as_of' => '2026-09-01']);
        $this->mock(TrainingAssignmentProvider::class)->shouldReceive('candidates')->once()->with('2026-09-01')->andReturn([$historical]);
        $corrected = app(TrainingRosterService::class)->correct($attendance, ['employee_profile_id' => $replacement->id, 'signature' => $this->signature()], $this->actor, 'Synthetic identity correction');
        self::assertSame($historical, $corrected->snapshot);
        self::assertSame('2026-09-02T02:00:00+00:00', $corrected->signed_at->toIso8601String());
        self::assertSame($originalReceipt, $corrected->client_submission_id);
        self::assertSame($this->employee->id, $corrected->audits()->where('action', 'attendance.corrected')->sole()->before['employee_profile_id']);
    }

    public function test_kiosk_grant_is_rechecked_and_audited_without_storing_its_secret(): void
    {
        $grant = $this->session->kioskGrants()->create(['token_hash' => hash('sha256', 'synthetic-grant'), 'expires_at' => now()->subMinute(), 'created_by' => $this->actor->id]);
        try {
            $this->service->sign($this->session, $this->employee->id, $this->signature(), (string) Str::uuid(), $grant->id);
            self::fail('Expired grant accepted.');
        } catch (ValidationException) {
            self::assertSame(0, TrainingAttendance::query()->count());
        }
        $grant->update(['expires_at' => now()->addHour()]);
        $record = $this->service->sign($this->session, $this->employee->id, $this->signature(), (string) Str::uuid(), $grant->id);
        $audit = $record->audits()->where('action', 'attendance.signed')->sole();
        self::assertSame($grant->id, $audit->after['kiosk_grant_id']);
        self::assertArrayNotHasKey('token_hash', $audit->after);
    }

    public function test_archiving_preserves_certified_export_and_disallows_writes(): void
    {
        $this->session = $this->service->transition($this->session, 'closed', $this->actor);
        $certified = $this->service->certify($this->session, $this->actor, $this->employee->id, $this->signature());
        $this->session = $this->service->transition($this->session, 'archived', $this->actor);
        self::assertSame($certified->snapshot['certification'], $this->service->snapshot($this->session)->snapshot['certification']);
        $this->expectException(ValidationException::class);
        $this->service->transition($this->session, 'open', $this->actor);
    }

    private function signature(bool $ink = true): string
    {
        $image = imagecreatetruecolor(300, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        if ($ink) {
            imagesetthickness($image, 5);
            imageline($image, 20, 70, 270, 20, imagecolorallocate($image, 0, 0, 0));
        }
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }
}
