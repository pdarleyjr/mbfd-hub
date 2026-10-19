<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams one evidence image (a finding photo or the inspector signature) of an
 * inspection the caller may view. The image is addressed by an opaque key that
 * the server derives from the inspection's own evidence, so no storage path is
 * ever accepted from the client or exposed as a public URL.
 */
class VehicleInspectionImageController extends Controller
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __invoke(
        Request $request,
        ApparatusInspection $inspection,
        string $key,
        EmployeeInspectionHistoryService $history,
    ): Response {
        $user = $request->user('web');
        abort_unless($user instanceof User, 401);
        abort_unless(
            $user->can('viewOwn', $inspection) || $user->can('view', $inspection),
            403,
        );

        $path = $history->resolveImagePath($inspection, $key);
        abort_if($path === null, 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        $bytes = (string) $disk->get($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(in_array($mime, self::ALLOWED_MIME, true), 404);

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => 'inline',
        ]);
    }
}
