<?php

declare(strict_types=1);

namespace App\Http\Controllers\TakeHomeInspections;

use App\Http\Controllers\Controller;
use App\Models\TakeHomeVehicleInspection;
use App\Models\TakeHomeVehicleInspectionPhoto;
use App\Models\User;
use App\Services\Fleet\TakeHomeInspectionPdfRenderer;
use App\Services\Fleet\TakeHomeInspectionPhotoStore;
use App\Support\Fleet\TakeHomeInspectionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Private photo upload/delivery, signature delivery and the inspection PDF. */
final class TakeHomeInspectionFileController extends Controller
{
    private const PRIVATE_HEADERS = [
        'Cache-Control' => 'private, no-store, max-age=0',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; sandbox",
    ];

    public function storePhoto(Request $request, TakeHomeInspectionPhotoStore $store): JsonResponse
    {
        $angles = array_merge(array_keys(TakeHomeVehicleInspection::PHOTO_ANGLES), [TakeHomeVehicleInspection::ISSUE_ANGLE]);
        $data = $request->validate([
            'photo' => ['required', 'file', 'max:'.TakeHomeInspectionPhotoStore::MAX_UPLOAD_KB],
            'angle' => ['required', Rule::in($angles)],
            'check_key' => ['nullable', 'required_if:angle,issue', Rule::in(array_keys(TakeHomeVehicleInspection::CHECKS))],
        ], [
            'photo.required' => 'Choose or take a photo first.',
            'photo.max' => 'That photo is larger than 30 MB. Take it again with the camera button.',
            'photo.uploaded' => 'The photo did not finish uploading. Check your connection and try again.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $photo = $store->store($request->file('photo'), $user, $data['angle'], $data['check_key'] ?? null);

        return response()->json([
            'uuid' => $photo->uuid,
            'url' => route('take-home-inspections.photo', $photo),
            'width' => $photo->width,
            'height' => $photo->height,
            'angle' => $photo->angle,
            'check_key' => $photo->check_key,
        ], 201, ['Cache-Control' => 'private, no-store']);
    }

    public function photo(Request $request, TakeHomeVehicleInspectionPhoto $photo): Response
    {
        abort_unless(TakeHomeInspectionAccess::canViewPhoto($request->user(), $photo), 404);
        $disk = Storage::disk(TakeHomeInspectionPhotoStore::disk());
        abort_unless($disk->exists($photo->path), 404);

        return response((string) $disk->get($photo->path), 200, self::PRIVATE_HEADERS + [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="'.$photo->uuid.'.jpg"',
        ]);
    }

    public function signature(Request $request, TakeHomeVehicleInspection $inspection): Response
    {
        abort_unless(TakeHomeInspectionAccess::canView($request->user(), $inspection), 404);
        $disk = Storage::disk(TakeHomeInspectionPhotoStore::disk());
        abort_unless($disk->exists($inspection->signature_path), 404);

        return response((string) $disk->get($inspection->signature_path), 200, self::PRIVATE_HEADERS + [
            'Content-Type' => 'image/png',
        ]);
    }

    public function pdf(Request $request, TakeHomeVehicleInspection $inspection, TakeHomeInspectionPdfRenderer $renderer): Response
    {
        abort_unless(TakeHomeInspectionAccess::canView($request->user(), $inspection), 404);
        $pdf = $renderer->render($inspection);
        $filename = $inspection->pdfFilename();
        $response = $request->boolean('inline') ? $pdf->stream($filename) : $pdf->download($filename);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
