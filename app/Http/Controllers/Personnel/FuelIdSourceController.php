<?php

declare(strict_types=1);

namespace App\Http\Controllers\Personnel;

use App\Http\Controllers\Controller;
use App\Models\FuelIdEvent;
use App\Models\FuelIdSource;
use App\Models\User;
use App\Services\Personnel\FuelIds\FuelIdAccess;
use App\Services\Personnel\FuelPinDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an imported fuel list (the preserved source record) to holders of the Fuel ID view permission.
 * Inline for the page's viewer, attachment for Download. Every request is checked and audited here.
 */
final class FuelIdSourceController extends Controller
{
    public function view(Request $request, FuelIdSource $source, FuelPinDirectory $directory): StreamedResponse
    {
        return $this->stream($request, $source, $directory, download: false);
    }

    public function download(Request $request, FuelIdSource $source, FuelPinDirectory $directory): StreamedResponse
    {
        return $this->stream($request, $source, $directory, download: true);
    }

    private function stream(Request $request, FuelIdSource $source, FuelPinDirectory $directory, bool $download): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && FuelIdAccess::canView($actor), 403);
        $disk = Storage::disk($source->disk);
        abort_unless($disk->exists($source->path), 404);
        $directory->record($download ? FuelIdEvent::SOURCE_DOWNLOADED : FuelIdEvent::SOURCE_VIEWED, $actor, source: $source);

        $inline = ! $download && $source->isPdf();
        $filename = preg_replace('~[\x00-\x1f\x7f"\\/]+~', '_', $source->original_name) ?: 'fuel-id-list';
        $disposition = HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            'fuel-id-list'.($source->isPdf() ? '.pdf' : ''),
        );

        return response()->stream(function () use ($disk, $source): void {
            $stream = $disk->readStream($source->path);
            if (! is_resource($stream)) {
                throw new RuntimeException('The private fuel list stream is unavailable.');
            }
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $source->mime_type ?: 'application/octet-stream',
            'Content-Length' => (string) $source->file_size,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; frame-ancestors 'self'",
        ]);
    }
}
