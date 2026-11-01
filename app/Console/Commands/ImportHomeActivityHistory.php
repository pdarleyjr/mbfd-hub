<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Home\HomeActivity;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Loads Home page visit history counted before the live counter existed (edge analytics, one number per hour).
 *
 *   php artisan home-activity:import-history hours.json
 *   cat hours.json | php artisan home-activity:import-history --dry-run
 *
 * Input is a JSON array of {"hour": "2026-10-02T13:00:00Z", "views": 12}. The numbers are SET, not added, so
 * running it again with the same file changes nothing. Live counts (`views`) are never touched.
 */
final class ImportHomeActivityHistory extends Command
{
    private const MAX_ROWS = 100_000;

    private const CHUNK = 500;

    /** @var string */
    protected $signature = 'home-activity:import-history
                            {path? : JSON file to read; omit to read standard input}
                            {--dry-run : Check the input and report what would be written, without writing}';

    /** @var string */
    protected $description = 'Set the imported (pre-counter) Home page visits for each hour from a JSON file';

    public function handle(): int
    {
        $json = $this->readInput();
        if ($json === null) {
            return self::FAILURE;
        }

        $rows = $this->validated($json);
        if ($rows === null) {
            return self::FAILURE;
        }

        $earliest = collect($rows)->filter(fn (array $row): bool => $row['imported_views'] > 0)->min('hour');
        if (! $this->option('dry-run')) {
            try {
                DB::transaction(function () use ($rows): void {
                    foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                        // New hours start with no live count; an existing hour only has `imported_views` replaced.
                        DB::table(HomeActivity::TABLE)->upsert($chunk, ['hour'], ['imported_views']);
                    }
                });
            } catch (Throwable $exception) {
                $this->error('Nothing was written: '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        $this->info(sprintf('%s %d hour rows.', $this->option('dry-run') ? 'Would write' : 'Wrote', count($rows)));
        $this->line($earliest === null
            ? 'No hour has any views.'
            : 'Earliest hour with views: '.CarbonImmutable::parse($earliest, 'UTC')->setTimezone(HubTimezone::NAME)->format('M j, Y g:i A T'));

        return self::SUCCESS;
    }

    private function readInput(): ?string
    {
        $path = $this->argument('path');
        $contents = is_string($path) && $path !== ''
            ? (is_file($path) && is_readable($path) ? file_get_contents($path) : false)
            : file_get_contents('php://stdin');

        if (! is_string($contents) || trim($contents) === '') {
            $this->error(is_string($path) && $path !== '' ? "Cannot read {$path}, or it is empty." : 'Nothing was provided on standard input.');

            return null;
        }

        return $contents;
    }

    /** @return list<array{hour: string, views: int, imported_views: int}>|null */
    private function validated(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->error('The input is not valid JSON.');

            return null;
        }

        if (! is_array($decoded) || ! array_is_list($decoded) || $decoded === [] || count($decoded) > self::MAX_ROWS) {
            $this->error('Expected a JSON array of 1 to '.self::MAX_ROWS.' rows like {"hour": "2026-10-02T13:00:00Z", "views": 12}.');

            return null;
        }

        $currentHour = CarbonImmutable::now('UTC')->startOfHour();
        $seen = [];
        $rows = [];
        foreach ($decoded as $index => $item) {
            $problem = null;
            $hour = null;
            if (! is_array($item) || ! is_string($item['hour'] ?? null) || ! is_int($item['views'] ?? null) || $item['views'] < 0) {
                $problem = 'needs a text "hour" and a whole-number "views" of 0 or more';
            } else {
                try {
                    $hour = CarbonImmutable::parse($item['hour'])->utc();
                } catch (Throwable) {
                    $problem = '"hour" is not a date and time';
                }
            }
            if ($problem === null) {
                $problem = match (true) {
                    $hour->minute !== 0 || $hour->second !== 0 => '"hour" must be exactly on the hour',
                    $hour->greaterThan($currentHour) => '"hour" is in the future',
                    isset($seen[$hour->timestamp]) => '"hour" appears more than once',
                    default => null,
                };
            }
            if ($problem !== null) {
                $this->error('Row '.($index + 1).': '.$problem.'. Nothing was written.');

                return null;
            }

            $seen[$hour->timestamp] = true;
            $rows[] = ['hour' => $hour->format('Y-m-d H:i:s'), 'views' => 0, 'imported_views' => $item['views']];
        }

        return $rows;
    }
}
