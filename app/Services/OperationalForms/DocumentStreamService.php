<?php

namespace App\Services\OperationalForms;

use App\Models\OperationalFormDocument;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentStreamService
{
    public function response(OperationalFormDocument $document, bool $download): StreamedResponse
    {
        $disk = Storage::disk($document->storage_disk);
        abort_unless($disk->exists($document->storage_path), 404);

        $filename = $this->safeFilename($document->display_name);
        $disposition = HeaderUtils::makeDisposition(
            $download ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
            $filename,
            'operational-form.pdf',
        );

        return response()->stream(function () use ($disk, $document): void {
            $stream = $disk->readStream($document->storage_path);
            if (! is_resource($stream)) {
                throw new RuntimeException('The private PDF stream is unavailable.');
            }
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) $document->file_size,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeFilename(string $filename): string
    {
        $filename = preg_replace('~[\r\n"\\\\/]+~', '_', $filename) ?: 'operational-form.pdf';

        return str_ends_with(strtolower($filename), '.pdf') ? $filename : $filename.'.pdf';
    }
}
