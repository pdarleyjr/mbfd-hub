<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline\Support;

use App\Jobs\DailyRoster\ProcessRosterImport;
use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\Parsing\TeleStaffRosterReport;
use App\Services\DailyRoster\Parsing\TeleStaffWorkbookParser;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

trait InteractsWithRosterPipeline
{
    /**
     * 09:30 Eastern on a B-shift duty day, in publish mode, with queues and error reporting faked.
     * The disk gets a private root because Storage::fake() empties one shared directory, so another
     * test process running at the same time could delete the attachments out from under these tests.
     */
    protected function preparePipeline(string $now = '2026-10-30 13:30:00', bool $fakeQueue = true): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mbfd-roster-pipeline-'.getmypid().'-'.Str::random(12);
        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');
        $this->beforeApplicationDestroyed(static fn () => (new Filesystem)->deleteDirectory($root));

        if ($fakeQueue) {
            Bus::fake();
        }
        Exceptions::fake();
        config(['daily_roster.mode' => 'publish', 'daily_roster.cleanup_enabled' => false]);
        $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
    }

    protected function parserReturns(TeleStaffRosterReport|Throwable ...$results): FakeWorkbookParser
    {
        $parser = new FakeWorkbookParser(...$results);
        $this->app->instance(TeleStaffWorkbookParser::class, $parser);

        return $parser;
    }

    protected function runProcess(RosterImport|int $import): void
    {
        $this->app->call([new ProcessRosterImport($import instanceof RosterImport ? (int) $import->getKey() : $import), 'handle']);
    }

    /** Runs one export through the job from a fresh ledger row and returns the row. */
    protected function importExport(TeleStaffRosterReport $report, array $overrides = []): RosterImport
    {
        $import = RosterPipelineFixtures::import($overrides);
        $this->parserReturns($report);
        $this->runProcess($import);

        return $import->fresh();
    }

    protected function pointerFor(string $dutyDate): ?RosterActiveVersion
    {
        return RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->first();
    }
}
