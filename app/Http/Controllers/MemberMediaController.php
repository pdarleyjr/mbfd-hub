<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Media\MemberMedia;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a member image through a signed address issued by a page that already
 * authorized the record (MemberMedia::url). Route middleware requires a signed-in
 * session and a valid, unexpired signature; the signature also names the viewer,
 * so a copied address is useless to anyone else.
 */
final class MemberMediaController extends Controller
{
    public function __invoke(Request $request, string $path): StreamedResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless(hash_equals((string) $user->getAuthIdentifier(), (string) $request->query('viewer', '')), 403);

        $safe = MemberMedia::safePath($path);
        abort_if($safe === null, 404);

        return MemberMedia::response($safe);
    }
}
