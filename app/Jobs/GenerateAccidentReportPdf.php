<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AccidentReport;
use App\Services\Accidents\AccidentReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Produces the official PDF for a finished accident report. Runs on the Operational Forms queue (same Node
 * runtime). Safe to repeat: the service skips a revision that already has its PDF. If every attempt fails the
 * report stays complete and its page offers "Produce PDF again".
 */
final class GenerateAccidentReportPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [15, 60];

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $reportId)
    {
        $this->onQueue('operational-forms');
    }

    public function handle(AccidentReportService $service): void
    {
        $report = AccidentReport::query()->find($this->reportId);
        if ($report !== null) {
            $service->generateDocument($report);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Accident report PDF could not be produced.', ['accident_report_id' => $this->reportId, 'exception' => $exception::class]);
    }
}
