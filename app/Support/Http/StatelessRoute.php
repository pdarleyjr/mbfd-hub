<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Http\Middleware\EnforceMemberBootstrapBoundary;
use App\Http\Middleware\EnsureCanonicalSessionIsCurrent;
use App\Http\Middleware\EnsureCityEmailReview;
use App\Http\Middleware\ForcePasswordChange;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Routes the browser (or an installed app) fetches on its own, with the member's cookies attached, while
 * the member is doing something else: release probes, service-worker script update checks, web manifests.
 *
 * A route in the web middleware group starts the session and saves it again before it answers, and saving
 * ages flash data by one step. Whichever request saves second therefore decides whether a flash message
 * survives: a background fetch that lands between a redirecting POST and the GET it redirects to takes the
 * flash ("Your reply was sent.") before the page can show it. These routes read and change no member state,
 * so they must not open the session at all, nor redirect a member who has a pending password change or
 * city-email review (a redirected worker script fails its update check).
 *
 * Usage: `Route::get(...)->withoutMiddleware(StatelessRoute::WITHOUT_MIDDLEWARE)`.
 */
final class StatelessRoute
{
    /** @var list<class-string> */
    public const WITHOUT_MIDDLEWARE = [
        StartSession::class,
        ShareErrorsFromSession::class,
        VerifyCsrfToken::class,
        EnforceMemberBootstrapBoundary::class,
        EnsureCanonicalSessionIsCurrent::class,
        ForcePasswordChange::class,
        EnsureCityEmailReview::class,
    ];
}
