<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Employee;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('postgres')]
final class TrainingRosterPostgresConcurrencyTest extends TestCase
{
    public function test_course_lock_serializes_two_public_starts_into_one_roster(): void
    {
        if (getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1' || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the explicitly configured disposable PostgreSQL database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        self::assertMatchesRegularExpression('/^mbfd_hub_test_[a-z0-9_]+$/', DB::connection()->getDatabaseName());
        self::assertMatchesRegularExpression('/^mbfd_test_[a-z0-9_]+$/', DB::connection()->getConfig('username'));

        $suffix = bin2hex(random_bytes(6));
        $user = User::factory()->create();
        $course = TrainingCourse::query()->create(['name' => 'Synthetic public start '.$suffix,
            'classifications' => ['EMS Training'], 'default_location' => 'Synthetic classroom', 'created_by' => $user->id]);
        $children = [];
        $applications = ['training-start-'.$suffix.'-1', 'training-start-'.$suffix.'-2'];
        try {
            DB::beginTransaction();
            TrainingCourse::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            foreach ($applications as $application) {
                $child = $this->publicStarter($course, $application);
                $children[] = $child;
                $child->start();
            }
            $deadline = microtime(true) + 30;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $blocked = DB::select('select application_name from pg_stat_activity where application_name in (?, ?) and wait_event_type = ?', [...$applications, 'Lock']);
                if (count($blocked) === 2) {
                    break;
                }
                foreach ($children as $child) {
                    if (! $child->isRunning() || microtime(true) >= $deadline) {
                        self::fail('Both public starters must reach the held course lock: '.$child->getErrorOutput());
                    }
                }
                usleep(10_000);
            } while (true);
            self::assertSame(0, $course->sessions()->count());
            DB::commit();

            foreach ($children as $child) {
                $child->wait();
                self::assertTrue($child->isSuccessful(), $child->getErrorOutput());
                self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', trim($child->getOutput()));
            }
            self::assertSame(trim($children[0]->getOutput()), trim($children[1]->getOutput()));
            $sessions = $course->sessions()->get();
            self::assertCount(1, $sessions);
            $session = $sessions->sole();
            self::assertSame((string) $session->id, trim($children[0]->getOutput()));
            self::assertSame('2026-10-15', $session->held_on->toDateString());
            self::assertSame('open', $session->status);
            self::assertNull($session->created_by);
            self::assertSame(1, $session->revision);
            self::assertSame(0, $session->attendances()->count());
            self::assertSame(1, $session->versions()->count());
            self::assertSame(1, $session->audits()->where('action', 'session.public_started')->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($children as $child) {
                if ($child->isRunning()) {
                    $child->stop();
                }
            }
            // The random course owns every row removed; never clear shared fixtures.
            $sessionIds = DB::table('training_sessions')->where('training_course_id', $course->id)->pluck('id');
            foreach (['training_roster_audits', 'training_roster_versions', 'training_kiosk_grants', 'training_attendances'] as $table) {
                DB::table($table)->whereIn('training_session_id', $sessionIds)->delete();
            }
            DB::table('training_sessions')->whereIn('id', $sessionIds)->delete();
            DB::table('training_courses')->where('id', $course->id)->delete();
            DB::table('users')->where('id', $user->id)->delete();
        }
    }

    public function test_session_lock_serializes_close_and_concurrent_identical_submissions(): void
    {
        if (getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1' || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the explicitly configured disposable PostgreSQL database.');
        }
        $suffix = bin2hex(random_bytes(6));
        $employee = Employee::query()->create(['employee_id' => 'TRAINING-RACE-'.$suffix, 'name' => 'Synthetic Training Race', 'rank' => 'Firefighter', 'roster_status' => 'active']);
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);
        $course = TrainingCourse::query()->create(['name' => 'Synthetic concurrency course', 'classifications' => ['Task Level/Company'], 'created_by' => $user->id]);
        $session = TrainingSession::query()->create(['public_id' => (string) Str::ulid(), 'training_course_id' => $course->id, 'course_name' => $course->name,
            'classifications' => $course->classifications, 'held_on' => now()->toDateString(), 'status' => 'open', 'revision' => 1, 'created_by' => $user->id]);
        $diskRoot = storage_path('framework/testing/training-concurrency-'.$suffix);
        config()->set('filesystems.disks.training_concurrency', ['driver' => 'local', 'root' => $diskRoot]);
        $children = [];
        try {
            DB::beginTransaction();
            TrainingSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $application = 'training-race-'.$suffix;
            $child = $this->writer($session, $employee, (string) Str::uuid(), $application, $diskRoot);
            $children[] = $child;
            $child->start();
            $deadline = microtime(true) + 15;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $state = DB::selectOne('select wait_event_type from pg_stat_activity where application_name = ?', [$application]);
                if ($state?->wait_event_type === 'Lock') {
                    break;
                }
                if (! $child->isRunning() || microtime(true) >= $deadline) {
                    self::fail('Signer did not reach the session lock barrier: '.$child->getErrorOutput());
                }
                usleep(10_000);
            } while (true);
            $session->update(['status' => 'closed']);
            DB::commit();
            $child->wait();
            self::assertTrue($child->isSuccessful(), $child->getErrorOutput());
            self::assertSame('rejected', trim($child->getOutput()));
            self::assertSame(0, $session->attendances()->count());

            $session->update(['status' => 'open']);
            $uuid = (string) Str::uuid();
            foreach ([1, 2] as $number) {
                $writer = $this->writer($session, $employee, $uuid, $application.'-'.$number, $diskRoot);
                $children[] = $writer;
                $writer->start();
            }
            foreach (array_slice($children, 1) as $writer) {
                $writer->wait();
                self::assertTrue($writer->isSuccessful(), $writer->getErrorOutput());
            }
            self::assertSame(trim($children[1]->getOutput()), trim($children[2]->getOutput()));
            self::assertStringStartsWith('accepted:', trim($children[1]->getOutput()));
            self::assertSame(1, $session->attendances()->count());
            self::assertSame(2, $session->fresh()->revision);
            self::assertCount(1, app(\App\Services\Training\TrainingRosterService::class)->snapshot($session)->snapshot['attendance']);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($children as $child) {
                if ($child->isRunning()) {
                    $child->stop();
                }
            }
            foreach (TrainingAttendance::query()->where('training_session_id', $session->id)->get() as $attendance) {
                Storage::disk('training_concurrency')->delete($attendance->signature['path']);
            }
            // Only these random synthetic fixtures in the explicitly disposable database.
            foreach (['training_roster_audits', 'training_roster_versions', 'training_kiosk_grants', 'training_attendances'] as $table) {
                DB::table($table)->where('training_session_id', $session->id)->delete();
            }
            DB::table('training_sessions')->where('id', $session->id)->delete();
            DB::table('training_courses')->where('id', $course->id)->delete();
            DB::table('employee_profile_events')->where('employee_id', $employee->id)->delete();
            DB::table('users')->where('id', $user->id)->delete();
            DB::table('employees')->where('id', $employee->id)->delete();
        }
    }

    private function publicStarter(TrainingCourse $course, string $application): Process
    {
        $script = <<<'PHP'
require getcwd().'/tests/bootstrap.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1'
    || Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') { exit(2); }
Illuminate\Support\Facades\DB::select("select set_config('application_name', ?, false)", [$argv[2]]);
Illuminate\Support\Carbon::setTestNow(Carbon\CarbonImmutable::parse('2026-10-15T14:00:00+00:00'));
$course = App\Models\Training\TrainingCourse::query()->findOrFail((int) $argv[1]);
$session = app(App\Services\Training\TrainingRosterService::class)->startPublicSession($course);
echo $session->id;
PHP;

        return new Process([PHP_BINARY, '-r', $script, (string) $course->id, $application], base_path(), timeout: 60);
    }

    private function writer(TrainingSession $session, Employee $employee, string $uuid, string $application, string $diskRoot): Process
    {
        $script = <<<'PHP'
require getcwd().'/tests/bootstrap.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1'
    || Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') { exit(2); }
Illuminate\Support\Facades\DB::select("select set_config('application_name', ?, false)", [$argv[4]]);
config()->set('filesystems.private', 'local');
config()->set('filesystems.disks.local.root', $argv[5]);
$employee = App\Models\Employee::query()->findOrFail((int) $argv[2]);
app()->instance(App\Services\Training\TrainingAssignmentProvider::class, new class($employee) extends App\Services\Training\TrainingAssignmentProvider {
    public function __construct(private App\Models\Employee $employee) {}
    public function candidates(string $asOf): array { return [['employee_profile_id'=>$this->employee->id,'employee_id'=>$this->employee->employee_id,
        'first_name'=>'Synthetic','last_name'=>'Race','display_name'=>$this->employee->name,'rank'=>'Firefighter','shift'=>'A Shift',
        'term_label'=>'Current assignment','assignment_id'=>'synthetic-race','assignment_source'=>'TELESTAFF_IMPORT','as_of'=>$asOf]]; }
});
$image = imagecreatetruecolor(300,100); imagefill($image,0,0,imagecolorallocate($image,255,255,255));
imagesetthickness($image,5); imageline($image,20,70,270,20,imagecolorallocate($image,0,0,0));
ob_start(); imagepng($image); $signature='data:image/png;base64,'.base64_encode(ob_get_clean()); imagedestroy($image);
try { $record=app(App\Services\Training\TrainingRosterService::class)->sign(App\Models\Training\TrainingSession::query()->findOrFail((int)$argv[1]),$employee->id,$signature,$argv[3]); echo 'accepted:'.$record->id; }
catch (Illuminate\Validation\ValidationException) { echo 'rejected'; }
PHP;

        return new Process([PHP_BINARY, '-r', $script,
            (string) $session->id, (string) $employee->id, $uuid, $application, $diskRoot], base_path(), timeout: 30);
    }
}
