<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Models\VehicleDocument;
use App\Services\Fleet\VehicleDocumentLibrary;
use App\Support\Fleet\VehicleDocumentAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VehicleDocumentFileController extends Controller
{
    public function download(Request $request, UnitMasterVehicle $vehicle, VehicleDocument $document, VehicleDocumentLibrary $library): StreamedResponse
    {
        abort_unless(VehicleDocumentAccess::canViewDocument($request->user(), $document, $vehicle), 404);

        return $this->deliver($request, $document, $library, false, $vehicle);
    }

    public function preview(Request $request, UnitMasterVehicle $vehicle, VehicleDocument $document, VehicleDocumentLibrary $library): StreamedResponse
    {
        abort_unless(VehicleDocumentAccess::canViewDocument($request->user(), $document, $vehicle) && $document->canPreview(), 404);

        return $this->deliver($request, $document, $library, true, $vehicle);
    }

    public function reviewDownload(Request $request, VehicleDocument $document, VehicleDocumentLibrary $library): StreamedResponse
    {
        $this->authorizeReview($request, $document);

        return $this->deliver($request, $document, $library, false);
    }

    public function reviewPreview(Request $request, VehicleDocument $document, VehicleDocumentLibrary $library): StreamedResponse
    {
        $this->authorizeReview($request, $document);
        abort_unless($document->canPreview(), 404);

        return $this->deliver($request, $document, $library, true);
    }

    private function authorizeReview(Request $request, VehicleDocument $document): void
    {
        abort_unless($document->review_status !== VehicleDocument::STATUS_VERIFIED
            && VehicleDocumentAccess::canManage($request->user())
            && VehicleDocumentAccess::canViewDocument($request->user(), $document), 404);
    }

    private function deliver(Request $request, VehicleDocument $document, VehicleDocumentLibrary $library, bool $inline, ?UnitMasterVehicle $vehicle = null): StreamedResponse
    {
        $stream = $library->verifiedStream($document);
        try {
            /** @var User $actor */
            $actor = $request->user();
            $library->recordEvent($document, $actor, $inline ? 'preview' : 'download', $vehicle === null ? [] : ['vehicle_id' => $vehicle->getKey()]);
        } catch (\Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
        $filename = $document->original_filename;
        $fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?: 'Vehicle-document';
        $response = response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $document->mime_type,
            'Content-Length' => (string) $document->size_bytes,
            'Content-Disposition' => HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $filename, $fallback),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Referrer-Policy' => 'no-referrer',
        ]);

        return $response;
    }
}
