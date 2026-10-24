<?php

declare(strict_types=1);

namespace App\Services\ResourceLibrary;

use App\Models\ResourceDocument;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams library files from the private disk. Same transport rules as the operational-forms documents:
 * private, no-store, nosniff, a sanitized filename, and no public URL to the bytes.
 */
final class ResourceDocumentStreamer
{
    public function __construct(private readonly ResourceLibraryStorage $storage) {}

    /** The browser-viewable PDF: the original when it is a PDF, otherwise the generated preview. */
    public function preview(ResourceDocument $document): StreamedResponse
    {
        $path = $document->previewDiskPath();
        abort_if($path === null, 404);

        $name = pathinfo($document->original_filename, PATHINFO_FILENAME).'.pdf';

        // No `sandbox` CSP here: Chrome refuses to open its PDF viewer inside a sandboxed document.
        return $this->stream($path, ResourceDocument::PDF_MIME, $name, HeaderUtils::DISPOSITION_INLINE, null);
    }

    /** The original file in its original format. */
    public function download(ResourceDocument $document): StreamedResponse
    {
        return $this->stream(
            $document->original_path,
            $document->original_mime,
            $document->original_filename,
            HeaderUtils::DISPOSITION_ATTACHMENT,
            "sandbox; default-src 'none'",
        );
    }

    private function stream(string $path, string $mime, string $filename, string $disposition, ?string $contentSecurityPolicy): StreamedResponse
    {
        $disk = $this->storage->disk();
        abort_unless($disk->exists($path), 404);

        $safe = preg_replace('~[\x00-\x1f\x7f"\\\\/]+~', '_', $filename) ?: 'document';
        $fallback = str_replace('%', '_', Str::ascii($safe)) ?: 'document';

        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string) $disk->size($path),
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $safe, $fallback),
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if ($contentSecurityPolicy !== null) {
            $headers['Content-Security-Policy'] = $contentSecurityPolicy;
        }

        return response()->stream(function () use ($disk, $path): void {
            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                throw new RuntimeException('The private library file is unavailable.');
            }
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }
}
