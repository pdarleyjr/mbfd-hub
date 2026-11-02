<?php

declare(strict_types=1);

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Support\Training\CommandBoardSandboxBundle;
use App\Support\Training\SandboxBundleUnavailable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Hosts the Command Board training sandbox: a static, network-free page that never talks to the live Command Board.
 *
 * Eligibility is the Home Quick Access rule and nothing more: a signed-in Hub member whose account is allowed to sign
 * in (the route's auth:web, the web group's canonical-session check, the password and city-email boundaries). There is
 * no PIN, no separate sign-in, and no Command300Access check, because the page carries no operational data and the
 * policy below gives it no way to fetch any. The live board (cmd.mbfdhub.com) is unaffected.
 *
 * The policy is closed on purpose: scripts and styles from this origin only, no connections of any kind
 * (connect-src 'none'), no workers, no framing, no forms. Nothing in the request changes the answer.
 */
final class CommandBoardSandboxController extends Controller
{
    /** Sent with the page, and kept by SecurityHeaders (it only fills in a policy the response does not carry). */
    public const CONTENT_SECURITY_POLICY = "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'none'; worker-src 'none'; manifest-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    public function __invoke(): Response
    {
        try {
            $html = CommandBoardSandboxBundle::configured()->html();
        } catch (SandboxBundleUnavailable $exception) {
            // Members get the friendly page; the reason (never a stack trace) goes to the log for the person who vendors.
            Log::warning('Command Board sandbox bundle is unavailable.', ['reason' => $exception->getMessage()]);

            return response()->view('errors.hub', [
                'code' => 503,
                'title' => 'The Command Board Sandbox is not available right now',
                'message' => 'The practice board could not be opened, so nothing was started. The live Command Board is not affected. Return to the Hub and try again later.',
            ], 503, ['Cache-Control' => 'no-store, private']);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'microphone=(), camera=(), geolocation=()',
            // A window the practice page opens gets no handle back to it, and it cannot reach one that opened it.
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ]);
    }
}
