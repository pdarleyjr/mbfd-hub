<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Sink for `Content-Security-Policy-Report-Only` violation reports from
 * browsers. Used as the observability gate before promoting the header to
 * enforcing `Content-Security-Policy` — see [SecurityHeaders.php](app/Http/Middleware/SecurityHeaders.php#L34)
 * for the 7-day clean-window policy.
 *
 * Two report formats can hit this endpoint:
 *  - Legacy (`Content-Type: application/csp-report`) — body is JSON-wrapped in
 *    a `csp-report` key.
 *  - Reporting API v1 (`Content-Type: application/reports+json`) — body is an
 *    array of report objects.
 *
 * Both shapes are logged at info level with a `[CSP]` tag for easy grep:
 *
 *     docker exec mbfd-hub-laravel grep -F '[CSP]' storage/logs/laravel-$(date +%Y-%m-%d).log
 *
 * The endpoint always responds 204 — browsers ignore the body anyway, and a
 * non-2xx response would cause them to retry / spam our logs.
 */
class CspReportController extends Controller
{
    public function store(Request $request): Response
    {
        $payload = $request->json()->all();

        // Both wire formats share enough shape that one log line covers both.
        // Defensive — a malformed report is a browser bug, not ours.
        $report = $payload['csp-report'] ?? $payload[0]['body'] ?? $payload;

        Log::info('[CSP] violation report', [
            'ua' => substr((string) $request->userAgent(), 0, 200),
            'ip' => $request->ip(),
            'report' => $report,
        ]);

        return response()->noContent();
    }
}
