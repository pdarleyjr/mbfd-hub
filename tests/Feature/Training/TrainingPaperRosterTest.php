<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingPaperAttendance;
use App\Models\Training\TrainingPaperReviewAudit;
use App\Models\Training\TrainingPaperRosterImport;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingPaperRosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TrainingPaperRosterTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    private Employee $employee;

    private array $manifest;

    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('filesystems.private', 'local');
        $this->reviewer = User::factory()->create(['account_status' => AccountStatus::Active]);
        $this->reviewer->assignRole(Role::findOrCreate('training_admin', 'web'));
        $this->employee = Employee::query()->create(['employee_id' => 'SYNTHETIC-PAPER', 'name' => 'Synthetic Paper Member', 'rank' => 'Firefighter']);
        $course = TrainingCourse::query()->create(['name' => 'Synthetic Course', 'classifications' => [], 'created_by' => $this->reviewer->id]);
        $moduleId = DB::table('training_modules')->insertGetId(['training_course_id' => $course->id, 'name' => 'Synthetic Module', 'created_by' => $this->reviewer->id]);
        $this->sourcePath = storage_path('framework/testing/synthetic-paper-original.pdf');
        @mkdir(dirname($this->sourcePath), 0700, true);
        $pdf = app('dompdf.wrapper')->loadHTML('<html><body>Synthetic source evidence</body></html>')->output();
        file_put_contents($this->sourcePath, $pdf);
        $this->manifest = ['schema_version' => 1, 'archive_sha256' => str_repeat('a', 64), 'reviewer_id' => $this->reviewer->id,
            'documents' => [['source_path' => $this->sourcePath, 'source_filename' => basename($this->sourcePath), 'source_sha256' => hash('sha256', $pdf),
                'page_count' => 1, 'held_on' => '2026-10-13', 'course_id' => $course->id, 'module_id' => $moduleId,
                'rows' => [$this->row('1', true), $this->row('2', false)]]]];
        Route::middleware('web')->group(base_path('routes/training-paper.php'));
    }

    protected function tearDown(): void
    {
        if (isset($this->sourcePath) && is_file($this->sourcePath)) {
            unlink($this->sourcePath);
        }
        parent::tearDown();
    }

    public function test_default_preview_writes_nothing_and_apply_replays_without_digital_artifacts(): void
    {
        $service = app(TrainingPaperRosterService::class);
        $result = $service->importManifest($this->manifest, $this->reviewer);
        self::assertSame(1, $result['approved']);
        self::assertSame(0, TrainingSession::query()->count());
        self::assertSame([], Storage::disk('local')->allFiles());
        $result = $service->importManifest($this->manifest, $this->reviewer, true);
        self::assertSame(1, $result['created']);
        self::assertSame(1, $service->importManifest($this->manifest, $this->reviewer, true)['replayed']);
        self::assertSame(1, TrainingSession::query()->count());
        self::assertSame(2, TrainingPaperAttendance::query()->count());
        self::assertSame(0, DB::table('training_attendances')->count());
        self::assertSame(0, DB::table('training_roster_versions')->count());
        self::assertSame('paper', TrainingSession::query()->first()->attendance_source);
        self::assertNull(TrainingSession::query()->first()->submitted_at);
    }

    public function test_transaction_failure_on_later_document_rolls_back_all_records(): void
    {
        $bad = $this->manifest;
        $second = $bad['documents'][0];
        $second['source_sha256'] = str_repeat('b', 64);
        $bad['documents'][] = $second;
        try {
            app(TrainingPaperRosterService::class)->importManifest($bad, $this->reviewer, true);
            self::fail('Tampered source accepted');
        } catch (ValidationException) {
            self::assertSame(0, TrainingSession::query()->count());
            self::assertSame(0, TrainingPaperRosterImport::query()->count());
            self::assertSame(0, TrainingPaperReviewAudit::query()->count());
        }
        self::assertSame(1, app(TrainingPaperRosterService::class)->importManifest($this->manifest, $this->reviewer, true)['created']);
    }

    public function test_unsigned_or_unconfirmed_or_noncanonical_approval_is_rejected(): void
    {
        foreach (['evidence_present' => false, 'identity_confirmed' => false, 'employee_profile_id' => 999999] as $field => $value) {
            $manifest = $this->manifest;
            $manifest['documents'][0]['rows'][0][$field] = $value;
            try {
                app(TrainingPaperRosterService::class)->importManifest($manifest, $this->reviewer, true);
                self::fail('Invalid approval accepted');
            } catch (ValidationException) {
                self::assertSame(0, TrainingPaperAttendance::query()->count());
            }
        }
    }

    public function test_source_locations_and_duplicate_employees_and_cross_course_module_are_rejected(): void
    {
        foreach (['location', 'employee', 'module', 'reviewer', 'pages'] as $case) {
            $manifest = $this->manifest;
            if ($case === 'location') {
                $manifest['documents'][0]['rows'][1]['row'] = '1';
            } elseif ($case === 'employee') {
                $manifest['documents'][0]['rows'][1] = $this->row('2', true);
            } elseif ($case === 'module') {
                $manifest['documents'][0]['module_id'] = 999999;
            } elseif ($case === 'reviewer') {
                $manifest['reviewer_id'] = 999999;
            } else {
                $manifest['documents'][0]['page_count'] = 2;
            }
            try {
                app(TrainingPaperRosterService::class)->importManifest($manifest, $this->reviewer, true);
                self::fail('Invalid manifest accepted: '.$case);
            } catch (ValidationException) {
                self::assertSame(0, TrainingPaperAttendance::query()->count());
            }
        }
    }

    public function test_review_requires_evidence_and_tracks_void_and_reassignment_without_erasing_observation(): void
    {
        $service = app(TrainingPaperRosterService::class);
        $service->importManifest($this->manifest, $this->reviewer, true);
        $entry = TrainingPaperAttendance::query()->where('review_status', 'approved')->firstOrFail();
        $raw = $entry->raw_transcription;
        $service->review($entry, $this->reviewer, 'voided', null, 'Synthetic wrong match', 1);
        self::assertNull($entry->fresh()->employee_profile_id);
        self::assertSame('voided', $entry->fresh()->review_status);
        $service->review($entry, $this->reviewer, 'approved', $this->employee->id, 'Synthetic evidence rechecked', 2);
        self::assertSame($raw, $entry->fresh()->raw_transcription);
        self::assertSame(3, $entry->audits()->count());
        $unsigned = TrainingPaperAttendance::query()->where('review_status', 'pending')->firstOrFail();
        $this->expectException(ValidationException::class);
        $service->review($unsigned, $this->reviewer, 'approved', $this->employee->id, 'Cannot invent ink', 1);
    }

    public function test_changed_manifest_cannot_silently_rewrite_import(): void
    {
        $service = app(TrainingPaperRosterService::class);
        $service->importManifest($this->manifest, $this->reviewer, true);
        $changed = $this->manifest;
        $changed['documents'][0]['rows'][0]['raw_transcription'] = 'Changed source claim';
        $this->expectException(ValidationException::class);
        $service->importManifest($changed, $this->reviewer, true);
    }

    public function test_stale_review_cannot_overwrite_a_newer_decision(): void
    {
        $service = app(TrainingPaperRosterService::class);
        $service->importManifest($this->manifest, $this->reviewer, true);
        $entry = TrainingPaperAttendance::query()->where('review_status', 'approved')->firstOrFail();
        $service->review($entry, $this->reviewer, 'voided', null, 'First reviewer correction', 1);
        try {
            $service->review($entry, $this->reviewer, 'approved', $this->employee->id, 'Stale second review', 1);
            self::fail('Stale review overwrote a decision');
        } catch (ValidationException) {
            self::assertSame('voided', $entry->fresh()->review_status);
            self::assertSame(2, $entry->fresh()->review_version);
            self::assertSame(2, $entry->audits()->count());
        }
    }

    public function test_validated_json_numeric_and_boolean_strings_are_normalized(): void
    {
        $manifest = $this->manifest;
        $manifest['documents'][0]['page_count'] = '1';
        $manifest['documents'][0]['rows'][0]['employee_profile_id'] = (string) $this->employee->id;
        $manifest['documents'][0]['rows'][0]['identity_confirmed'] = '1';
        $manifest['documents'][0]['rows'][0]['evidence_present'] = '1';
        self::assertSame(1, app(TrainingPaperRosterService::class)->importManifest($manifest, $this->reviewer)['approved']);
    }

    public function test_private_original_requires_training_role_and_rejects_tampering(): void
    {
        $service = app(TrainingPaperRosterService::class);
        $service->importManifest($this->manifest, $this->reviewer, true);
        $import = TrainingPaperRosterImport::query()->firstOrFail();
        $this->actingAs($this->reviewer)->get(route('training.paper.source', $import))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $outsider = User::factory()->create(['account_status' => AccountStatus::Active]);
        $this->actingAs($outsider)->get(route('training.paper.source', $import))->assertForbidden();
        Storage::disk($import->disk)->put($import->path, '%PDF-tampered');
        $this->expectException(ValidationException::class);
        $service->sourceBytes($import, $this->reviewer);
    }

    public function test_viewer_cannot_apply_or_review(): void
    {
        $viewer = User::factory()->create(['account_status' => AccountStatus::Active]);
        $viewer->assignRole(Role::findOrCreate('training_viewer', 'web'));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(TrainingPaperRosterService::class)->importManifest($this->manifest, $viewer, true);
    }

    public function test_paper_session_cannot_create_a_digital_export_or_version(): void
    {
        app(TrainingPaperRosterService::class)->importManifest($this->manifest, $this->reviewer, true);
        $session = TrainingSession::query()->firstOrFail();
        $this->actingAs($this->reviewer)->get('/training-rosters/'.$session->id.'/export/pdf')->assertStatus(409);
        $this->get('/training-rosters/'.$session->id.'/export/xlsx')->assertStatus(409);
        self::assertSame(0, DB::table('training_roster_versions')->count());
    }

    public function test_cli_is_dry_run_by_default_and_rejects_conflicting_identity_flags(): void
    {
        $manifestPath = dirname($this->sourcePath).'/synthetic-manifest.json';
        file_put_contents($manifestPath, json_encode($this->manifest, JSON_THROW_ON_ERROR));
        try {
            $flags = ['--course-id' => $this->manifest['documents'][0]['course_id'],
                '--module-id' => $this->manifest['documents'][0]['module_id'],
                '--reviewer' => $this->reviewer->id, '--source-manifest' => $manifestPath];
            $this->artisan('training:import-paper', $flags)->assertSuccessful();
            self::assertSame(0, TrainingSession::query()->count());
            $this->artisan('training:import-paper', array_replace($flags, ['--course-id' => 999999, '--apply' => true]))->assertFailed();
            self::assertSame(0, TrainingSession::query()->count());
        } finally {
            unlink($manifestPath);
        }
    }

    private function row(string $number, bool $approved): array
    {
        return ['page' => 1, 'row' => $number, 'raw_transcription' => 'Synthetic member observation',
            'evidence_present' => $approved, 'review_status' => $approved ? 'approved' : 'pending',
            'employee_profile_id' => $approved ? $this->employee->id : null, 'identity_confirmed' => $approved,
            'review_reason' => $approved ? 'Synthetic independently reviewed fixture' : 'Synthetic illegible entry'];
    }
}
