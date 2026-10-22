<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Services\Defects\DefectContinuityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Two submissions of the same item on the same physical vehicle must serialize on the
 * apparatus row lock and end as ONE defect with TWO observations, never a duplicate or a
 * unique-index failure. Guarded to an explicitly disposable PostgreSQL database.
 */
#[Group('postgres')]
final class DefectContinuityPostgresTest extends TestCase
{
    public function test_concurrent_submissions_of_one_item_produce_one_defect_and_two_observations(): void
    {
        if (getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') !== '1' || DB::connection()->getDriverName() !== 'pgsql') {
            if (getenv('REQUIRE_POSTGRES_INTEGRATION') === 'true') {
                $this->fail('The guarded disposable PostgreSQL database is required.');
            }
            $this->markTestSkipped('Requires the guarded disposable PostgreSQL database.');
        }
        Http::preventStrayRequests();
        Queue::fake();
        $apparatus = Apparatus::create(['name' => 'Continuity fixture', 'unit_id' => 'PG-DC-'.uniqid(), 'type' => 'Engine', 'status' => 'In Service']);
        $results = [['id' => 'cab', 'name' => 'Cab', 'items' => [['id' => 'cab-item-1', 'name' => 'Radio', 'status' => 'Missing']]]];
        $make = fn (): ApparatusInspection => ApparatusInspection::create([
            'apparatus_id' => $apparatus->id, 'operator_name' => 'Concurrency fixture', 'rank' => 'Firefighter',
            'review_status' => 'pending_review', 'results' => $results,
        ]);
        $first = $make();
        $second = $make();
        $ready = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dc-ready-'.uniqid();

        // The child process commits for $first only after it has held the apparatus row for a moment,
        // so the parent's transaction for $second must wait on the same lock.
        $child = new Process([PHP_BINARY, '-d', 'extension=pdo_pgsql', '-r', <<<'PHP'
require getenv('DC_BASE').'/vendor/autoload.php';
$app = require getenv('DC_BASE').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\DB::transaction(function () {
    $apparatus = App\Models\Apparatus::query()->lockForUpdate()->findOrFail((int) getenv('DC_APPARATUS'));
    file_put_contents(getenv('DC_READY'), '1');
    usleep(900000);
    $inspection = App\Models\ApparatusInspection::query()->findOrFail((int) getenv('DC_INSPECTION'));
    app(App\Services\Defects\DefectContinuityService::class)->recordFindings($inspection, $apparatus, $inspection->results, [], []);
});
PHP], base_path(), [
            'DC_BASE' => base_path(),
            'DC_APPARATUS' => (string) $apparatus->id,
            'DC_INSPECTION' => (string) $first->id,
            'DC_READY' => $ready,
            'SystemRoot' => (string) getenv('SystemRoot'),
            'WINDIR' => (string) getenv('WINDIR'),
        ]);
        try {
            $child->start();
            $deadline = microtime(true) + 15;
            while (! is_file($ready) && microtime(true) < $deadline) {
                if (! $child->isRunning()) {
                    $this->fail('Child process exited early: '.$child->getErrorOutput().$child->getOutput());
                }
                usleep(20_000);
            }
            $this->assertFileExists($ready, 'The child must hold the apparatus row before the parent submits.');

            DB::transaction(function () use ($apparatus, $second): void {
                $locked = Apparatus::query()->lockForUpdate()->findOrFail($apparatus->id);
                app(DefectContinuityService::class)->recordFindings($second, $locked, $second->results, [], []);
            });
            $child->wait();
            $this->assertTrue($child->isSuccessful(), $child->getErrorOutput().$child->getOutput());

            $this->assertSame(1, ApparatusDefect::query()->where('apparatus_id', $apparatus->id)->count());
            $defect = ApparatusDefect::query()->where('apparatus_id', $apparatus->id)->firstOrFail();
            $this->assertSame($first->id, $defect->first_reported_inspection_id);
            $this->assertSame($second->id, $defect->last_observed_inspection_id);
            $this->assertSame(
                ['confirmed_again', 'first_reported'],
                DB::table('apparatus_defect_observations')->where('apparatus_defect_id', $defect->id)->orderBy('observation')->pluck('observation')->all(),
            );
        } finally {
            if ($child->isRunning()) {
                $child->stop();
            }
            @unlink($ready);
        }
    }
}
