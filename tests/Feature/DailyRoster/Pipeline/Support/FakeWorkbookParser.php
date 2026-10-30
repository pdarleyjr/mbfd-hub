<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline\Support;

use App\Services\DailyRoster\Parsing\TeleStaffRosterReport;
use LogicException;
use Throwable;

/** Stands in for TeleStaffWorkbookParser: hands back the next scripted report, or throws it. */
final class FakeWorkbookParser
{
    public int $calls = 0;

    /** @var list<TeleStaffRosterReport|Throwable> */
    private array $results;

    public function __construct(TeleStaffRosterReport|Throwable ...$results)
    {
        $this->results = array_values($results);
    }

    public function parse(string $absolutePath, string $sourceSha256): TeleStaffRosterReport
    {
        $this->calls++;
        if (! is_file($absolutePath)) {
            throw new LogicException('The job handed the parser a path that does not exist.');
        }

        $result = array_shift($this->results) ?? throw new LogicException('The parser was called more often than scripted.');
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
