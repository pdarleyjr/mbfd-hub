<?php

declare(strict_types=1);

namespace App\Http\Controllers\Narcotics;

use App\Http\Controllers\Controller;
use App\Models\Narcotics\NarcoticsCheck;
use App\Models\User;
use App\Services\Narcotics\NarcoticsCheckAccess;
use App\Services\Narcotics\NarcoticsImageStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Delivers one size of one narcotics check photo from the private disk, only to the member who sent it
 * or a Captain 5 reviewer. Anyone else, and every request for a check that does not exist, gets the same
 * 404, so the route never confirms that a check exists. The response is never cacheable, never sniffed
 * and never framed.
 */
final class CheckImageController extends Controller
{
    public function __construct(
        private readonly NarcoticsCheckAccess $access,
        private readonly NarcoticsImageStore $store,
    ) {}

    public function show(Request $request, string $uuid, string $variant): Response
    {
        $user = $request->user();
        $check = $user instanceof User && in_array($variant, NarcoticsImageStore::VARIANTS, true)
            ? NarcoticsCheck::query()->where('uuid', Str::lower($uuid))->first()
            : null;
        if ($check === null || ! $this->access->canView($user, $check)) {
            abort(404);
        }

        $file = $this->store->read($check, $variant);
        if ($file === null) {
            abort(404);
        }

        return response($file['bytes'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Length' => (string) strlen($file['bytes']),
            'Content-Disposition' => 'inline; filename="narcotics-check-'.$variant.'.'.$file['extension'].'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, noarchive',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
