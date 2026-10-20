<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingPaperAttendance;
use App\Models\Training\TrainingPaperReviewAudit;
use App\Models\Training\TrainingPaperRosterImport;
use App\Models\Training\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('postgres')]
final class TrainingPaperPostgresConcurrencyTest extends TestCase
{
    private TrainingSession $session;

    private TrainingCourse $course;

    /** @var list<User> */
    private array $reviewers = [];

    /** @var list<Employee> */
    private array $employees = [];

    /** @var list<TrainingPaperAttendance> */
    private array $entries = [];

    /** @var list<Process> */
    private array $children = [];

    private TrainingPaperRosterImport $sourceImport;

    private string $diskRoot;

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1' || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the explicitly configured disposable PostgreSQL database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        self::assertMatchesRegularExpression('/^mbfd_hub_test_[a-z0-9_]+$/', DB::connection()->getDatabaseName());
        self::assertMatchesRegularExpression('/^mbfd_test_[a-z0-9_]+$/', DB::connection()->getConfig('username'));

        $this->suffix = bin2hex(random_bytes(6));
        $role = Role::findOrCreate('training_admin', 'web');
        foreach ([1, 2] as $number) {
            $reviewer = User::factory()->create(['account_status' => AccountStatus::Active]);
            $reviewer->assignRole($role);
            $this->reviewers[] = $reviewer;
            $this->employees[] = Employee::query()->create([
                'employee_id' => 'PAPER-RACE-'.$this->suffix.'-'.$number,
                'name' => 'Synthetic Paper Race '.$number, 'rank' => 'Firefighter', 'roster_status' => 'active',
            ]);
        }
        $this->course = TrainingCourse::query()->create([
            'name' => 'Synthetic paper concurrency '.$this->suffix, 'classifications' => [], 'created_by' => $this->reviewers[0]->id,
        ]);
        $this->session = TrainingSession::query()->create([
            'public_id' => (string) Str::ulid(), 'training_course_id' => $this->course->id, 'course_name' => $this->course->name,
            'classifications' => [], 'held_on' => '2026-10-13', 'status' => 'paper', 'attendance_source' => 'paper',
            'revision' => 1, 'created_by' => $this->reviewers[0]->id,
        ]);
        $this->diskRoot = storage_path('framework/testing/paper-concurrency-'.$this->suffix);
        config()->set('filesystems.disks.local.root', $this->diskRoot);
        Storage::forgetDisk('local');
        $bytes = app('dompdf.wrapper')->loadHTML('<html><body>Synthetic paper concurrency source '.$this->suffix.'</body></html>')->output();
        $hash = hash('sha256', $bytes);
        $path = 'training-paper/originals/'.$hash.'.pdf';
        self::assertTrue(Storage::disk('local')->put($path, $bytes, ['visibility' => 'private']));
        $this->sourceImport = TrainingPaperRosterImport::query()->create([
            'training_session_id' => $this->session->id, 'source_sha256' => $hash, 'archive_sha256' => str_repeat('a', 64),
            'manifest_sha256' => str_repeat('b', 64), 'source_filename' => 'synthetic-concurrency.pdf',
            'disk' => 'local', 'path' => $path, 'page_count' => 1, 'importer_version' => 'synthetic-concurrency',
            'imported_by' => $this->reviewers[0]->id, 'imported_at' => now(),
        ]);
        foreach ([1, 2] as $row) {
            $this->entries[] = TrainingPaperAttendance::query()->create([
                'training_paper_roster_import_id' => $this->sourceImport->id, 'training_session_id' => $this->session->id,
                'employee_profile_id' => null, 'source_page' => 1, 'source_row' => (string) $row,
                'raw_transcription' => 'Synthetic preserved source row '.$row, 'evidence_present' => true,
                'review_status' => 'pending', 'review_version' => 1, 'reason' => 'Synthetic initial pending review',
            ]);
        }
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($this->children as $child) {
                if ($child->isRunning()) {
                    $child->stop();
                }
            }
            // Every deletion is scoped to this random fixture, in the guarded disposable database.
            if (isset($this->session)) {
                $entryIds = DB::table('training_paper_attendances')->where('training_session_id', $this->session->id)->pluck('id');
                DB::table('training_paper_review_audits')->whereIn('training_paper_attendance_id', $entryIds)->delete();
                DB::table('training_paper_attendances')->where('training_session_id', $this->session->id)->delete();
                DB::table('training_paper_roster_imports')->where('training_session_id', $this->session->id)->delete();
                DB::table('training_sessions')->where('id', $this->session->id)->delete();
            }
            if (isset($this->course)) {
                DB::table('training_courses')->where('id', $this->course->id)->delete();
            }
            foreach ($this->reviewers as $reviewer) {
                DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $reviewer->id)->delete();
                DB::table('users')->where('id', $reviewer->id)->delete();
            }
            foreach ($this->employees as $employee) {
                DB::table('employee_profile_events')->where('employee_id', $employee->id)->delete();
                DB::table('employees')->where('id', $employee->id)->delete();
            }
            if (isset($this->sourceImport)) {
                Storage::disk('local')->delete($this->sourceImport->path);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_concurrent_approvals_of_different_rows_for_one_employee_accept_exactly_one(): void
    {
        $outputs = $this->race([
            [$this->entries[0], $this->reviewers[0], $this->employees[0]],
            [$this->entries[1], $this->reviewers[1], $this->employees[0]],
        ]);
        sort($outputs);
        self::assertSame(['approved', 'rejected'], $outputs);
        $entries = TrainingPaperAttendance::query()->where('training_session_id', $this->session->id)->get();
        self::assertSame(1, $entries->where('review_status', 'approved')->count());
        self::assertSame(1, $entries->where('review_status', 'pending')->count());
        self::assertSame(1, $entries->where('employee_profile_id', $this->employees[0]->id)->count());
        self::assertSame(2, $entries->where('review_status', 'approved')->sole()->review_version);
        self::assertSame(1, $entries->where('review_status', 'pending')->sole()->review_version);
        self::assertSame(1, TrainingPaperReviewAudit::query()->whereIn('training_paper_attendance_id', $entries->modelKeys())->count());
    }

    public function test_concurrent_stale_review_of_one_row_cannot_replace_the_winning_identity(): void
    {
        $outputs = $this->race([
            [$this->entries[0], $this->reviewers[0], $this->employees[0]],
            [$this->entries[0], $this->reviewers[1], $this->employees[1]],
        ]);
        $winner = array_search('approved', $outputs, true);
        $sorted = $outputs;
        sort($sorted);
        self::assertSame(['approved', 'rejected'], $sorted);
        self::assertIsInt($winner);
        $entry = $this->entries[0]->fresh();
        self::assertSame($this->employees[$winner]->id, $entry->employee_profile_id);
        self::assertSame($this->reviewers[$winner]->id, $entry->reviewed_by);
        self::assertSame(2, $entry->review_version);
        self::assertSame('Synthetic preserved source row 1', $entry->raw_transcription);
        self::assertSame(1, $entry->audits()->count());
        self::assertSame('pending', $this->entries[1]->fresh()->review_status);
    }

    public function test_reviewer_role_revoked_while_waiting_for_lock_cannot_approve(): void
    {
        $this->assertRevokedWhileWaiting('role');
    }

    public function test_reviewer_security_version_changed_while_waiting_for_lock_cannot_approve(): void
    {
        $this->assertRevokedWhileWaiting('security_version');
    }

    private function assertRevokedWhileWaiting(string $change): void
    {
        $outputs = $this->race([[$this->entries[0], $this->reviewers[0], $this->employees[0]]], function () use ($change): void {
            if ($change === 'role') {
                $this->reviewers[0]->removeRole('training_admin');
            } else {
                DB::table('users')->where('id', $this->reviewers[0]->id)->increment('security_version');
            }
        });
        self::assertSame(['forbidden'], $outputs);
        $entry = $this->entries[0]->fresh();
        self::assertSame('pending', $entry->review_status);
        self::assertSame(1, $entry->review_version);
        self::assertNull($entry->employee_profile_id);
        self::assertSame(0, $entry->audits()->count());
    }

    /**
     * @param  list<array{TrainingPaperAttendance, User, Employee}>  $decisions
     * @return list<string>
     */
    private function race(array $decisions, ?\Closure $beforeRelease = null): array
    {
        DB::beginTransaction();
        TrainingSession::query()->whereKey($this->session->id)->lockForUpdate()->firstOrFail();
        $applications = [];
        foreach ($decisions as $number => [$entry, $reviewer, $employee]) {
            $application = 'paper-review-'.$this->suffix.'-'.$number;
            $applications[] = $application;
            $child = $this->reviewer($entry, $reviewer, $employee, $application);
            $this->children[] = $child;
            $child->start();
        }
        $deadline = microtime(true) + 30;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $blocked = DB::table('pg_stat_activity')->whereIn('application_name', $applications)->where('wait_event_type', 'Lock')->count();
            if ((int) $blocked === count($decisions)) {
                break;
            }
            foreach ($this->children as $child) {
                if (! $child->isRunning() || microtime(true) >= $deadline) {
                    self::fail('Every reviewer must reach the held session lock: '.$child->getErrorOutput());
                }
            }
            usleep(10_000);
        } while (true);
        self::assertSame(0, TrainingPaperAttendance::query()->where('training_session_id', $this->session->id)->where('review_status', 'approved')->count());
        $beforeRelease?->__invoke();
        DB::commit();
        $outputs = [];
        foreach ($this->children as $child) {
            $child->wait();
            self::assertTrue($child->isSuccessful(), $child->getErrorOutput());
            $outputs[] = trim($child->getOutput());
        }

        return $outputs;
    }

    private function reviewer(TrainingPaperAttendance $entry, User $reviewer, Employee $employee, string $application): Process
    {
        $script = <<<'PHP'
require getcwd().'/tests/bootstrap.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1'
    || Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') { exit(2); }
Illuminate\Support\Facades\DB::select("select set_config('application_name', ?, false)", [$argv[4]]);
config()->set('filesystems.disks.local.root', $argv[5]);
Illuminate\Support\Facades\Storage::forgetDisk('local');
$actor = App\Models\User::query()->findOrFail((int) $argv[2]);
$entry = App\Models\Training\TrainingPaperAttendance::query()->findOrFail((int) $argv[1]);
try {
    app(App\Services\Training\TrainingPaperRosterService::class)->review(
        $entry, $actor, 'approved', (int) $argv[3], 'Synthetic concurrent evidence review', 1
    );
    echo 'approved';
} catch (Illuminate\Validation\ValidationException) {
    echo 'rejected';
} catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
    if ($error->getStatusCode() !== 403) { throw $error; }
    echo 'forbidden';
}
PHP;

        return new Process([PHP_BINARY, '-r', $script, (string) $entry->id, (string) $reviewer->id,
            (string) $employee->id, $application, $this->diskRoot], base_path(), timeout: 60);
    }
}
