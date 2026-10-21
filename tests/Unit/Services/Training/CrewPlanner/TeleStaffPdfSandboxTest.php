<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Training\CrewPlanner;

use App\Services\Training\CrewPlanner\TeleStaffPdfProcessingException;
use App\Services\Training\CrewPlanner\TeleStaffPdfSandbox;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class TeleStaffPdfSandboxTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'synthetic-crew-sandbox-');
        file_put_contents($this->path, '%PDF-1.4 SYNTHETIC TEST ONLY');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
        parent::tearDown();
    }

    public function test_child_uses_array_arguments_hard_timeout_explicit_memory_and_private_pipe_snapshot(): void
    {
        $snapshot = ['schedule_date' => '2026-10-21', 'shift' => 'B', 'source_sha256' => hash_file('sha256', $this->path), 'parsed_assignments' => []];
        $process = $this->createMock(Process::class);
        $process->expects(self::once())->method('setTimeout')->with(15)->willReturnSelf();
        $process->expects(self::once())->method('run')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn(json_encode($snapshot));
        $process->method('isRunning')->willReturn(false);
        $factory = function (array $command, string $root) use ($process): Process {
            self::assertSame(PHP_BINARY, $command[0]);
            self::assertContains('memory_limit=128M', $command);
            self::assertContains('max_execution_time=10', $command);
            self::assertContains('log_errors=0', $command);
            self::assertSame($root.'/scripts/parse-training-crew-pdf.php', $command[count($command) - 2]);
            self::assertSame(realpath($this->path), $command[count($command) - 1]);

            return $process;
        };
        self::assertSame($snapshot, (new TeleStaffPdfSandbox($factory))->parse($this->path));
        self::assertFileExists($this->path);
    }

    public function test_timeout_stops_child_and_never_leaks_process_exception_or_source_text(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('setTimeout')->willReturnSelf();
        $process->method('getTimeout')->willReturn(15.0);
        $process->method('getCommandLine')->willReturn('PRIVATE SOURCE TEXT');
        $process->method('run')->willThrowException(new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL));
        $process->method('isRunning')->willReturn(true);
        $process->expects(self::once())->method('stop')->with(0);
        try {
            (new TeleStaffPdfSandbox(fn (): Process => $process))->parse($this->path);
            self::fail('A timed out process was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertInstanceOf(TeleStaffPdfProcessingException::class, $exception);
            self::assertSame(TeleStaffPdfProcessingException::PROCESS_TIMEOUT, $exception->reason);
            self::assertStringNotContainsString('PRIVATE', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function test_memory_failure_bad_json_oversized_output_and_wrong_hash_fail_closed(): void
    {
        foreach (['memory', 'invalid_json', 'excessive_output', 'wrong_hash'] as $failure) {
            $process = $this->createMock(Process::class);
            $process->method('setTimeout')->willReturnSelf();
            $process->method('run')->willReturn($failure === 'memory' ? 255 : 0);
            $process->method('isSuccessful')->willReturn($failure !== 'memory');
            $process->method('isRunning')->willReturn(false);
            $process->method('getOutput')->willReturn(match ($failure) {
                'invalid_json' => 'PRIVATE SOURCE TEXT',
                'excessive_output' => str_repeat('x', 4194305),
                default => json_encode(['schedule_date' => '2026-10-21', 'shift' => 'B', 'parsed_assignments' => [], 'source_sha256' => str_repeat('a', 64)]),
            });
            try {
                (new TeleStaffPdfSandbox(fn (): Process => $process))->parse($this->path);
                self::fail('Unsafe child output accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertInstanceOf(TeleStaffPdfProcessingException::class, $exception);
                self::assertSame($failure === 'memory' ? TeleStaffPdfProcessingException::PROCESS_FAILED : TeleStaffPdfProcessingException::OUTPUT_INVALID, $exception->reason);
                self::assertStringNotContainsString('PRIVATE', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }
    }

    public function test_missing_path_is_rejected_before_spawning(): void
    {
        $called = false;
        $sandbox = new TeleStaffPdfSandbox(function () use (&$called): Process {
            $called = true;

            return new Process([]);
        });
        try {
            $sandbox->parse($this->path.'-absent');
            self::fail('Missing path accepted.');
        } catch (TeleStaffPdfProcessingException $exception) {
            self::assertSame(TeleStaffPdfProcessingException::REPORT_UNAVAILABLE, $exception->reason);
            self::assertFalse($called);
        }
    }

    public function test_process_creation_and_start_exceptions_are_distinct_and_never_retained(): void
    {
        foreach (['creation', 'start', 'running'] as $stage) {
            $process = $this->createMock(Process::class);
            $process->method('setTimeout')->willReturnSelf();
            $process->method('run')->willThrowException($stage === 'start'
                ? new ProcessStartFailedException($process, 'PRIVATE REPORT /private/path')
                : new \RuntimeException('PRIVATE REPORT /private/path'));
            $process->method('isRunning')->willReturn(false);
            $factory = static function () use ($stage, $process): Process {
                if ($stage === 'creation') {
                    throw new \LogicException('PRIVATE REPORT /private/path');
                }

                return $process;
            };
            try {
                (new TeleStaffPdfSandbox($factory))->parse($this->path);
                self::fail('Process failure accepted.');
            } catch (TeleStaffPdfProcessingException $exception) {
                self::assertSame(match ($stage) {
                    'creation' => TeleStaffPdfProcessingException::PROCESS_UNAVAILABLE,
                    'start' => TeleStaffPdfProcessingException::PROCESS_START_FAILED,
                    default => TeleStaffPdfProcessingException::PROCESS_FAILED,
                }, $exception->reason);
                self::assertStringNotContainsString('PRIVATE', $exception->getMessage());
                self::assertStringNotContainsString('/private/path', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }
    }

    public function test_known_worker_exit_codes_have_only_allowlisted_reasons(): void
    {
        foreach ([20 => 'invalid_report', 21 => 'process_unavailable', 22 => 'process_failed', 23 => 'output_invalid', 126 => 'process_start_failed', 127 => 'process_start_failed', 255 => 'process_failed'] as $exit => $reason) {
            $process = $this->createMock(Process::class);
            $process->method('setTimeout')->willReturnSelf();
            $process->method('run')->willReturn($exit);
            $process->method('isSuccessful')->willReturn(false);
            $process->method('getExitCode')->willReturn($exit);
            $process->method('isRunning')->willReturn(false);
            $process->expects(self::never())->method('getErrorOutput');
            $process->expects(self::never())->method('getOutput');
            try {
                (new TeleStaffPdfSandbox(fn (): Process => $process))->parse($this->path);
                self::fail('Failed worker accepted.');
            } catch (TeleStaffPdfProcessingException $exception) {
                self::assertSame($reason, $exception->reason);
                self::assertNull($exception->getPrevious());
            }
        }
        $exception = new TeleStaffPdfProcessingException('PRIVATE REPORT /private/path');
        self::assertSame('process_failed', $exception->reason);
        self::assertStringNotContainsString('PRIVATE', $exception->getMessage());
    }

    public function test_actual_worker_rejects_invalid_report_with_silent_fixed_exit_code(): void
    {
        $worker = dirname(__DIR__, 5).'/scripts/parse-training-crew-pdf.php';
        $process = new Process([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', $worker, $this->path]);
        $process->setTimeout(15);
        self::assertSame(20, $process->run());
        self::assertSame('', $process->getOutput());
        self::assertSame('', $process->getErrorOutput());
        try {
            (new TeleStaffPdfSandbox)->parse($this->path);
            self::fail('Invalid report accepted.');
        } catch (TeleStaffPdfProcessingException $exception) {
            self::assertSame(TeleStaffPdfProcessingException::INVALID_REPORT, $exception->reason);
            self::assertNull($exception->getPrevious());
        }
        $process = new Process([PHP_BINARY, $worker]);
        $process->setTimeout(15);
        self::assertSame(21, $process->run());
        self::assertSame('', $process->getOutput());
        self::assertSame('', $process->getErrorOutput());
    }

    public function test_actual_subprocess_decodes_synthetic_daily_pdf_and_validates_source_hash(): void
    {
        $content = '';
        foreach ([[48, 300, 'Wednesday, October 21, 2026'], [48, 280, 'B Shift'],
            [173, 220, 'SYNTHETIC, EXAMPLE'], [311, 220, '90001'], [351, 220, 'SP'],
            [494.7, 220, '08:00'], [515.9, 220, '20:00'], [548, 220, '12']] as [$x, $y, $text]) {
            $content .= sprintf("q .36040324 0 0 .36040324 0 0 cm BT /F1 18 Tf 1 0 0 1 %.8F %.8F Tm (%s) Tj ET Q\n", $x / .36040324, $y / .36040324, $text);
        }
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [4 0 R] /Count 1 >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents 5 0 R >>',
            '<< /Length '.strlen($content).">>\nstream\n".$content.'endstream'];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
        file_put_contents($this->path, $pdf);
        $snapshot = (new TeleStaffPdfSandbox)->parse($this->path);
        self::assertSame('2026-10-21', $snapshot['schedule_date']);
        self::assertSame(hash('sha256', $pdf), $snapshot['source_sha256']);
        self::assertCount(1, $snapshot['parsed_assignments']);
        self::assertSame('90001', $snapshot['parsed_assignments'][0]['raw_employee_id']);
        self::assertSame($pdf, file_get_contents($this->path));
    }

    public function test_missing_worker_dependencies_exit_silently_even_when_error_display_is_enabled(): void
    {
        $root = $this->path.'-worker';
        mkdir($root);
        mkdir($root.'/scripts');
        $worker = $root.'/scripts/parse-training-crew-pdf.php';
        copy(dirname(__DIR__, 5).'/scripts/parse-training-crew-pdf.php', $worker);
        try {
            $process = new Process([PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=1', $worker, $this->path]);
            $process->setTimeout(15);
            self::assertSame(21, $process->run());
            self::assertSame('', $process->getOutput());
            self::assertSame('', $process->getErrorOutput());
        } finally {
            unlink($worker);
            rmdir($root.'/scripts');
            rmdir($root);
        }
    }
}
