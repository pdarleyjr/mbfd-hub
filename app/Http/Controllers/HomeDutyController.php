<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DailyRoster\HomeDutyStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Home greeting block, for a page left open: the same text the page rendered, so a new time of day, a new
 * duty day or a newly published roster reaches it without a reload. Personal, so nothing here may be stored by a
 * cache. It reads the same read model as the page and never opens a workbook.
 */
final class HomeDutyController extends Controller
{
    private const NO_STORE = ['Cache-Control' => 'no-store, private'];

    public function show(Request $request, HomeDutyStatus $status): Response|JsonResponse
    {
        // A timer fetch between a redirecting POST and the page it redirects to must not take that page's flash message.
        $request->session()->reflash();

        $lines = $status->forMember($request->user('web'));
        $etag = $lines->version();
        foreach (explode(',', (string) $request->header('If-None-Match')) as $candidate) {
            if (trim(preg_replace('/\AW\//', '', trim($candidate)) ?? '', '"') === $etag) {
                return response('', 304, self::NO_STORE + ['ETag' => '"'.$etag.'"']);
            }
        }

        return response()->json($lines->toArray(), 200, self::NO_STORE + ['ETag' => '"'.$etag.'"']);
    }
}
