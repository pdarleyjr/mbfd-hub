<?php

declare(strict_types=1);

namespace App\Jobs\DailyRoster;

use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\Parsing\TeleStaffRosterBlockSummary;
use App\Services\DailyRoster\Parsing\TeleStaffRosterReport;
use App\Services\DailyRoster\Parsing\TeleStaffWorkbookException;
use App\Services\DailyRoster\Parsing\TeleStaffWorkbookParser;
use App\Services\DailyRoster\RosterAnomalyGuard;
use App\Services\DailyRoster\RosterIncidents;
use App\Services\DailyRoster\RosterPipelineException;
use App\Services\DailyRoster\RosterPublisher;
use App\Services\DailyRoster\RosterStateRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Claims one ledger row, parses its workbook and hands a clean export to the publisher. The queue never
 * retries this job: retries come only from the ledger's schedule, so a crash at any point is found and
 * repaired by daily-roster:reconcile. Parsing happens outside any transaction.
 */
final class ProcessRosterImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const LIVE_STATES = [
        RosterImport::STATE_PUBLISHED, RosterImport::STATE_VERIFIED, RosterImport::STATE_CLEANED,
        RosterImport::STATE_SUPERSEDED, RosterImport::STATE_DUPLICATE,
    ];

    private const IN_PROGRESS_STATES = [RosterImport::STATE_PARSING, RosterImport::STATE_VALIDATED];

    private const HOLD_WARNING_PREFIX = 'hold:';

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('default');
        $this->timeout = (int) config('daily_roster.processing.job_timeout_seconds', 55);
    }

    public function handle(RosterStateRecorder $recorder, RosterAnomalyGuard $guard, RosterPublisher $publisher): void
    {
        $import = RosterImport::query()->find($this->importId);
        if ($import === null || config('daily_roster.mode') === 'off') {
            return;
        }

        try {
            if ($import->state === RosterImport::STATE_CLASSIFIED) {
                $import = $recorder->transition($import, RosterImport::STATE_QUEUED);
            }
            if ($import->state === RosterImport::STATE_QUEUED && $this->markDuplicateOfLive($import, $recorder)) {
                return;
            }

            $claimed = $recorder->claim($import);
            if ($claimed === null) {
                return;
            }

            $report = $this->readReport($claimed, $recorder);
            if ($report !== null) {
                $this->evaluate($recorder->transition($claimed, RosterImport::STATE_VALIDATED, self::parsedAttributes($report), 'parsed'), $report, $recorder, $guard, $publisher);
            }
        } catch (RosterPipelineException $exception) {
            if (! $exception->isConflict()) {
                $this->failInProgress($recorder, $exception->errorCode, []);
            }
        } catch (Throwable $exception) {
            // Never report the original: a database error's SQL can carry roster values.
            $name = class_basename($exception);
            report(new RosterPipelineException('process_unexpected_'.$name));
            $this->failInProgress($recorder, 'unexpected_error', ['exception' => $name]);
        }
    }

    /** Same workbook bytes as an import that already took effect: no further effect, and no parse. */
    private function markDuplicateOfLive(RosterImport $import, RosterStateRecorder $recorder): bool
    {
        if (blank($import->attachment_sha256)) {
            return false;
        }

        $live = RosterImport::query()
            ->where('attachment_sha256', $import->attachment_sha256)
            ->whereKeyNot($import->getKey())
            ->whereIn('state', self::LIVE_STATES)
            ->orderBy('id')
            ->first();
        if ($live === null) {
            return false;
        }

        // Point at the original, so a duplicate of a duplicate still leads to what took effect.
        $recorder->transition($import, RosterImport::STATE_DUPLICATE, [
            'outcome' => RosterImport::OUTCOME_DUPLICATE,
            'duplicate_of_import_id' => $live->duplicate_of_import_id ?? $live->getKey(),
        ], 'attachment_hash_matches_live_import');
        CleanupRosterImport::dispatchIfEnabled((int) $import->getKey());

        return true;
    }

    /** @return TeleStaffRosterReport|null Null when the row has already been failed */
    private function readReport(RosterImport $import, RosterStateRecorder $recorder): ?TeleStaffRosterReport
    {
        if (blank($import->attachment_disk) || blank($import->attachment_path) || blank($import->attachment_sha256)) {
            return $this->failPermanently($import, 'source_file_missing', $recorder);
        }

        $disk = Storage::disk((string) $import->attachment_disk);
        if (! $disk->exists((string) $import->attachment_path)) {
            return $this->failPermanently($import, 'source_file_missing', $recorder);
        }

        $path = $disk->path((string) $import->attachment_path);
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            $recorder->failRetryable($import, 'read_failed');

            return null;
        }
        if (! hash_equals((string) $import->attachment_sha256, $hash)) {
            return $this->failPermanently($import, 'source_hash_mismatch', $recorder);
        }

        try {
            return app(TeleStaffWorkbookParser::class)->parse($path, $hash);
        } catch (TeleStaffWorkbookException $exception) {
            $context = $exception->sourceRow === null ? [] : ['source_row' => $exception->sourceRow];
            if ($exception->errorCode === 'read_failed') {
                $recorder->failRetryable($import, $exception->errorCode, $context);

                return null;
            }

            return $this->failPermanently($import, $exception->errorCode, $recorder, $context);
        }
    }

    /** @param array<string, mixed> $context */
    private function failPermanently(RosterImport $import, string $code, RosterStateRecorder $recorder, array $context = []): null
    {
        $recorder->transition($import, RosterImport::STATE_PERMANENT_FAILED, ['error_code' => $code], 'workbook_failed_closed', $context);
        $recorder->supersedeOlderFailures($import);
        app(RosterIncidents::class)->report($code, $import->received_at->format('Y-m-d'));

        return null;
    }

    private function evaluate(RosterImport $import, TeleStaffRosterReport $report, RosterStateRecorder $recorder, RosterAnomalyGuard $guard, RosterPublisher $publisher): void
    {
        $reasons = $guard->check($import, $report);

        if (config('daily_roster.mode') !== 'publish') {
            $recorder->transition($import, RosterImport::STATE_SHADOWED, [
                'warnings' => self::warningsWithHolds($report, $reasons),
            ], 'shadow_mode', $reasons === [] ? [] : ['reasons' => $reasons]);

            return;
        }

        if ($reasons !== []) {
            $recorder->transition($import, RosterImport::STATE_NEEDS_REVIEW, [
                'error_code' => $reasons[0],
                'warnings' => self::warningsWithHolds($report, $reasons),
            ], 'anomaly_hold', ['reasons' => $reasons]);
            if ($report->generatedAt !== null) {
                $publisher->supersedeOlderHeld($import, CarbonImmutable::parse($report->generatedAt)->utc(), 'newer_export_held');
            }
            app(RosterIncidents::class)->report($reasons[0], $report->dutyDate);

            return;
        }

        $publisher->publish($import, $report);
        $this->dispatchNextStep($import);
    }

    private function dispatchNextStep(RosterImport $import): void
    {
        $state = RosterImport::query()->whereKey($import->getKey())->value('state');
        if ($state === RosterImport::STATE_PUBLISHED) {
            VerifyRosterImport::dispatch($this->importId)->afterCommit();
        } elseif ($state === RosterImport::STATE_DUPLICATE || $state === RosterImport::STATE_SUPERSEDED) {
            CleanupRosterImport::dispatchIfEnabled($this->importId);
        }
    }

    /** @param array<string, mixed> $context */
    private function failInProgress(RosterStateRecorder $recorder, string $code, array $context): void
    {
        $import = RosterImport::query()->find($this->importId);
        if ($import !== null && in_array($import->state, self::IN_PROGRESS_STATES, true)) {
            $recorder->failRetryable($import, $code, $context);
        }

        Log::warning('daily_roster.process_failed', ['import_id' => $this->importId, 'code' => $code]);
    }

    /** @return array<string, mixed> */
    private static function parsedAttributes(TeleStaffRosterReport $report): array
    {
        return [
            'duty_date' => $report->dutyDate,
            'shift_letter' => $report->shift,
            'source_generated_at' => $report->generatedAt === null ? null : CarbonImmutable::parse($report->generatedAt)->utc(),
            'parser_version' => $report->parserVersion,
            'layout_fingerprint' => $report->layoutFingerprint,
            'normalized_sha256' => $report->normalizedSha256(),
            'section_count' => count($report->sections),
            'assignment_count' => $report->assignmentCount(),
            'unique_employee_count' => $report->uniqueEmployeeCount(),
            'other_blocks' => array_map(static fn (TeleStaffRosterBlockSummary $block): array => [
                'title_row' => $block->titleRow,
                'division' => $block->division,
                'shift_label' => $block->shiftLabel,
                'section_count' => $block->sectionCount,
                'entry_count' => $block->entryCount,
            ], $report->otherBlocks),
            'warnings' => $report->warnings,
        ];
    }

    /**
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private static function warningsWithHolds(TeleStaffRosterReport $report, array $reasons): array
    {
        return [...$report->warnings, ...array_map(static fn (string $reason): string => self::HOLD_WARNING_PREFIX.$reason, $reasons)];
    }
}
