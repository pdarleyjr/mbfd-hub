<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use Closure;
use InvalidArgumentException;
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
            throw new InvalidArgumentException('Choose a private daily report PDF smaller than 8 MB.');
        }
        $sourceHash = @hash_file('sha256', $realPath);
        if (! is_string($sourceHash)) {
            throw new InvalidArgumentException('The private daily report is no longer available.');
        }
        $command = [PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'max_execution_time=10',
            '-d', 'display_errors=0', '-d', 'display_startup_errors=0', '-d', 'log_errors=0',
            $root.'/scripts/parse-training-crew-pdf.php', $realPath];
        $process = $this->processFactory === null ? new Process($command, $root) : ($this->processFactory)($command, $root);
        if (! $process instanceof Process) {
            throw new InvalidArgumentException('The daily report processor is unavailable.');
        }
        $process->setTimeout(15);
        try {
            $process->run();
            if (! $process->isSuccessful()) {
                throw new \RuntimeException;
            }
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
        } catch (\Throwable) {
            // Never attach the Process, command output or previous exception: any
            // of those may contain confidential source text or a private path.
            throw new InvalidArgumentException('This PDF could not be processed safely. Try a complete daily TeleStaff report.');
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }
}
