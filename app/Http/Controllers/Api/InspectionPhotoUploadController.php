<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\Inspections\InspectionPhotoUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One Daily Checkout photo per request (see InspectionPhotoUploads). Idempotent on the
 * phone's photo id: a retry answers 200 with the photo already stored.
 */
final class InspectionPhotoUploadController extends Controller
{
    public function store(
        Request $request,
        InspectionPhotoUploads $uploads,
        AuthenticatedMemberContextResolver $memberContextResolver,
    ): JsonResponse {
        $actor = $memberContextResolver->resolve($request)->actor();
        $actor->requireEmployee();
        $data = $request->validate([
            'client_photo_id' => ['required', 'uuid'],
            'photo' => ['required', 'file', 'max:'.InspectionPhotoUploads::MAX_UPLOAD_KB],
        ], [
            'photo.required' => 'Choose or take a photo first.',
            'photo.max' => 'That photo is larger than 25 MB. Take it again with the camera.',
            'photo.uploaded' => 'The photo did not finish uploading. It stays on this device and will be sent again.',
        ]);

        ['upload' => $upload, 'created' => $created] = $uploads->store($request->file('photo'), $actor->userId(), (string) $data['client_photo_id']);

        return response()->json([
            'photo_id' => $upload->uuid,
            'status' => $upload->status,
            'bytes' => $upload->bytes,
        ], $created ? 201 : 200, ['Cache-Control' => 'private, no-store']);
    }
}
