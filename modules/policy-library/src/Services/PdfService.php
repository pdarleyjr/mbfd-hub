<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Services;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class PdfService
{
    public function inspect(string $path): array
    {
        if (! is_file($path) || filesize($path) > config('policy-library.max_upload_kb') * 1024) {
            throw ValidationException::withMessages(['pdf' => 'The PDF is missing or exceeds the upload limit.']);
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages(['pdf' => 'The PDF could not be read.']);
        }
        try {
            $signature = fread($stream, 5);
        } finally {
            fclose($stream);
        }
        if ($signature !== '%PDF-' || (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf') {
            throw ValidationException::withMessages(['pdf' => 'Upload a PDF document.']);
        }
        app(MalwareScanner::class)->scan($path);
        $this->run([config('policy-library.qpdf_binary'), '--check', $path]);
        $info = $this->run([config('policy-library.pdfinfo_binary'), $path]);
        if (! preg_match('/^Pages:\s+(\d+)\s*$/m', $info, $pages) || (int) $pages[1] < 1
            || preg_match('/^Encrypted:\s+yes/m', $info)) {
            throw ValidationException::withMessages(['pdf' => 'Upload an unencrypted PDF with at least one page.']);
        }

        return ['page_count' => (int) $pages[1], 'sha256' => hash_file('sha256', $path)];
    }

    public function pageText(string $path): array
    {
        $text = $this->run([config('policy-library.pdftotext_binary'), '-layout', '-enc', 'UTF-8', $path, '-']);

        return explode("\f", $text);
    }

    public function replaceRange(string $current, int $start, int $end, string $replacement, string $output): array
    {
        $original = $this->inspect($current);
        $incoming = $this->inspect($replacement);
        if ($start < 1 || $end < $start || $end > $original['page_count']) {
            throw ValidationException::withMessages(['range' => 'Choose pages within the current document.']);
        }
        $arguments = [config('policy-library.qpdf_binary'), '--remove-unreferenced-resources=no', '--object-streams=generate', '--empty', '--pages'];
        if ($start > 1) {
            array_push($arguments, $current, '1-'.($start - 1));
        }
        array_push($arguments, $replacement, '1-z');
        if ($end < $original['page_count']) {
            array_push($arguments, $current, ($end + 1).'-z');
        }
        array_push($arguments, '--', $output);
        $this->run($arguments);
        $result = $this->inspect($output);
        if ($result['page_count'] !== $original['page_count'] - ($end - $start + 1) + $incoming['page_count']) {
            throw ValidationException::withMessages(['pdf' => 'The replacement PDF page count did not validate.']);
        }

        return $result;
    }

    private function run(array $arguments): string
    {
        $process = new Process($arguments, null, null, null, 180);
        $process->run();
        // qpdf exits 3 on warnings. Warnings require administrator review, not silent acceptance.
        if (! $process->isSuccessful()) {
            report(new \RuntimeException('Policy library PDF processing failed (exit '.$process->getExitCode().').'));
            throw ValidationException::withMessages(['pdf' => 'The PDF could not be validated. Upload a valid, unencrypted PDF.']);
        }

        return $process->getOutput();
    }
}
