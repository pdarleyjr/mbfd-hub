<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\Concerns;

use App\Services\Notifications\PushDeliveryTrace;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

/** Captures what the application writes to its default log channel, so tests can assert on (and against) it. */
trait CapturesPushLog
{
    private ?TestHandler $logCapture = null;

    protected function captureLog(): TestHandler
    {
        $this->logCapture = new TestHandler;
        Log::driver()->getLogger()->pushHandler($this->logCapture);

        return $this->logCapture;
    }

    /** @return list<array<string, mixed>> the context of every push attempt line, in order */
    protected function attemptLines(): array
    {
        return array_values(array_map(
            static fn (LogRecord $record): array => $record->context,
            array_filter(
                $this->logCapture?->getRecords() ?? [],
                static fn (LogRecord $record): bool => $record->message === PushDeliveryTrace::LOG_MESSAGE,
            ),
        ));
    }

    /** Everything logged, as one string, for "this never appears" assertions. */
    protected function everythingLogged(): string
    {
        return implode("\n", array_map(
            static fn (LogRecord $record): string => $record->message.' '.json_encode($record->context, JSON_PARTIAL_OUTPUT_ON_ERROR),
            $this->logCapture?->getRecords() ?? [],
        ));
    }
}
