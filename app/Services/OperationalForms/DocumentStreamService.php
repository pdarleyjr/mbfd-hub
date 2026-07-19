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
        $inline = ! $download && $document->isInlinePreviewable();
        $disposition = HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            'operational-form-file',
        );

        return response()->stream(function () use ($disk, $document): void {
            $stream = $disk->readStream($document->storage_path);
            if (! is_resource($stream)) {
                throw new RuntimeException('The private document stream is unavailable.');
            }
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Length' => (string) $document->file_size,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'",
        ]);
    }

    private function safeFilename(string $filename): string
    {
        return preg_replace('~[\x00-\x1f\x7f"\\\\/]+~', '_', $filename) ?: 'operational-form-file';
    }
}
