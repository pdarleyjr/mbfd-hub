<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use Closure;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** A bounded child process, independent of the web worker's PHP resource settings. */
final class TeleStaffPdfSandbox
{
    public function __construct(private readonly ?Closure $processFactory = null) {}

    public function parse(string $path): array
    {
        $root = dirname(__DIR__, 4);
        $realPath = realpath($path);
        $publicRoot = str_replace('\\', '/', $root).'/public/';
        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)
            || is_link($path) || str_starts_with(str_replace('\\', '/', $realPath), $publicRoot)
            || filesize($realPath) > TeleStaffDailyPdfParser::MAX_BYTES) {
            throw new TeleStaffPdfProcessingException(TeleStaffPdfProcessingException::REPORT_UNAVAILABLE);
        }
        $sourceHash = @hash_file('sha256', $realPath);
        if (! is_string($sourceHash)) {
            throw new TeleStaffPdfProcessingException(TeleStaffPdfProcessingException::REPORT_UNAVAILABLE);
        }
        $command = [PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'max_execution_time=10',
            '-d', 'display_errors=0', '-d', 'display_startup_errors=0', '-d', 'log_errors=0',
            $root.'/scripts/parse-training-crew-pdf.php', $realPath];
        try {
            $process = $this->processFactory === null ? new Process($command, $root) : ($this->processFactory)($command, $root);
            if (! $process instanceof Process) {
                throw new \RuntimeException;
            }
            $process->setTimeout(15);
        } catch (\Throwable) {
            throw new TeleStaffPdfProcessingException(TeleStaffPdfProcessingException::PROCESS_UNAVAILABLE);
        }
        $reason = TeleStaffPdfProcessingException::PROCESS_FAILED;
        try {
            $process->run();
            if (! $process->isSuccessful()) {
                throw new TeleStaffPdfProcessingException(match ($process->getExitCode()) {
                    20 => TeleStaffPdfProcessingException::INVALID_REPORT,
                    21 => TeleStaffPdfProcessingException::PROCESS_UNAVAILABLE,
                    23 => TeleStaffPdfProcessingException::OUTPUT_INVALID,
                    126, 127 => TeleStaffPdfProcessingException::PROCESS_START_FAILED,
                    default => TeleStaffPdfProcessingException::PROCESS_FAILED,
                });
            }
            $reason = TeleStaffPdfProcessingException::OUTPUT_INVALID;
            $output = $process->getOutput();
            if (strlen($output) > 4194304) {
                throw new \RuntimeException;
            }
            $snapshot = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($snapshot) || ! is_string($snapshot['schedule_date'] ?? null)
                || ! in_array($snapshot['shift'] ?? null, ['A', 'B', 'C'], true)
                || ! is_array($snapshot['parsed_assignments'] ?? null)
                || ! hash_equals($sourceHash, (string) ($snapshot['source_sha256'] ?? ''))) {
                throw new \RuntimeException;
            }

            return $snapshot;
        } catch (ProcessTimedOutException) {
            throw new TeleStaffPdfProcessingException(TeleStaffPdfProcessingException::PROCESS_TIMEOUT);
        } catch (ProcessStartFailedException) {
            throw new TeleStaffPdfProcessingException(TeleStaffPdfProcessingException::PROCESS_START_FAILED);
        } catch (TeleStaffPdfProcessingException $exception) {
            throw $exception;
        } catch (\Throwable) {
            // Never attach the Process, command output or previous exception: any
            // of those may contain confidential source text or a private path.
            throw new TeleStaffPdfProcessingException($reason);
        } finally {
            try {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            } catch (\Throwable) {
                throw new TeleStaffPdfProcessingException(TeleStaffPdfProcessingException::PROCESS_FAILED);
            }
        }
    }
}
