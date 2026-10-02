<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Services\MalwareScanner;
use Symfony\Component\Process\Process;

final class ScannerTest extends TestCase
{
    public function test_scanner_streams_the_complete_original_and_requires_explicit_clean_response(): void
    {
        mkdir($this->privateRoot, 0700, true);
        $path = $this->privateRoot.'/upload.pdf';
        file_put_contents($path, str_repeat('scanner-protocol-fixture', 12000));
        [$server, $port] = $this->startScanner('stream: OK');
        config(['policy-library.scanner_enabled' => true, 'policy-library.clamd_host' => '127.0.0.1', 'policy-library.clamd_port' => $port]);
        try {
            app(MalwareScanner::class)->scan($path);
            $server->wait();
            $this->assertSame(0, $server->getExitCode(), $server->getErrorOutput());
            $report = json_decode(substr($server->getOutput(), strpos($server->getOutput(), "\n") + 1), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(hash_file('sha256', $path), $report['sha256']);
            $this->assertGreaterThan(1, $report['chunks']);
        } finally {
            $server->stop();
        }
    }

    public function test_infected_and_unrecognized_scanner_responses_fail_closed(): void
    {
        mkdir($this->privateRoot, 0700, true);
        $path = $this->privateRoot.'/upload.pdf';
        file_put_contents($path, 'scanner-test-document');
        foreach (['stream: Eicar-Test-Signature FOUND', 'UNKNOWN COMMAND'] as $response) {
            [$server, $port] = $this->startScanner($response);
            config(['policy-library.scanner_enabled' => true, 'policy-library.clamd_host' => '127.0.0.1', 'policy-library.clamd_port' => $port]);
            try {
                app(MalwareScanner::class)->scan($path);
                $this->fail('An unsafe scanner response was accepted.');
            } catch (ValidationException $exception) {
                $this->assertSame('The document did not pass safety checking. Upload a safe PDF.', $exception->errors()['pdf'][0]);
            } finally {
                $server->stop();
            }
        }
    }

    private function startScanner(string $response): array
    {
        $process = new Process([PHP_BINARY, __DIR__.'/fixtures/clamd.php', $response]);
        $process->setTimeout(15);
        $process->start();
        $this->assertTrue($process->waitUntil(fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, "\n")), 'The local scanner fixture did not start.');

        return [$process, (int) strtok($process->getOutput(), "\n")];
    }
}
