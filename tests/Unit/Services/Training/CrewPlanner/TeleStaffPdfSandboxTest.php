<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Training\CrewPlanner;

use App\Services\Training\CrewPlanner\TeleStaffPdfSandbox;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
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
        } catch (InvalidArgumentException) {
            self::assertFalse($called);
        }
    }
}
