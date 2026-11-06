<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use App\Support\Media\MemberMedia;
use Illuminate\Http\Request;
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

        // Private disk first, then a public copy photos:make-private has not moved yet.
        $bytes = MemberMedia::get($path);
        abort_if($bytes === null || $bytes === '', 404);
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
