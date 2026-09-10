<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserIdentityLink;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\AuthentikOidcClient;
use App\Services\Identity\CanonicalLoginDestination;
use App\Services\Identity\CanonicalSessionIssuer;
use App\Services\Identity\FederationLoginAttempt;
use App\Services\Identity\IdentityProviderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuthentikLoginController extends Controller
{
    public function redirect(
        Request $request,
        AuthentikOidcClient $oidc,
        FederationLoginAttempt $federation,
    ): RedirectResponse {
        abort_unless(in_array(config('identity.mode'), ['hybrid', 'authentik'], true), 404);
        $loginAttempt = null;
        if ($federation->requested($request)) {
            abort_unless($federation->current($request) !== null, 409);
            $loginAttempt = (string) $request->query('login_attempt');
        }

        return redirect()->away($oidc->begin($request, $loginAttempt));
    }

    public function callback(
        Request $request,
        AuthentikOidcClient $oidc,
        IdentityProviderService $identities,
        CanonicalSessionIssuer $sessions,
        FederationLoginAttempt $federation,
        AccountSecurityService $security,
    ): Response {
        try {
            $result = $oidc->complete($request);
            $claims = $result['claims'];
            $subject = (string) ($claims['sub'] ?? '');
            $link = UserIdentityLink::query()
                ->where('provider', 'authentik')
                ->where('subject', $subject)
                ->first();
            $user = $link?->user()->first();
            if (! $link instanceof UserIdentityLink
                || ! $user instanceof User
                || $link->status !== 'active'
                || ! $identities->enabledFor($user)
                || ! $user->isUpstreamIdentityEnabled()) {
                throw new RuntimeException('The upstream subject is not linked to a current local identity.');
            }
            if ($user->getRawOriginal('account_status') === AccountStatus::PendingActivation->value) {
                $user = $security->changeStatus($user, AccountStatus::Active, 'authentik activation', now());
            }
            if (! $user->isAuthenticationAllowed()) {
                throw new RuntimeException('The local identity is not eligible for authentication.');
            }
            $registeredId = $sessions->issue($request, $user);
            if ($result['end_session_endpoint'] !== null) {
                $request->session()->put('auth.authentik_end_session_endpoint', $result['end_session_endpoint']);
            }
            $link->forceFill(['last_verified_at' => now(), 'status' => 'active'])->save();
            Log::info('canonical_authentication_succeeded', [
                'user_id' => $user->getKey(),
                'employee_profile_id' => $user->employee_profile_id,
                'authentication_session_id' => $registeredId,
                'authentication_method' => 'authentik_oidc',
            ]);

            if ($result['login_attempt'] !== null) {
                $request->query->set('login_attempt', $result['login_attempt']);

                return $federation->complete($request);
            }

            return redirect(app(CanonicalLoginDestination::class)->resolve(
                $user,
                $request->session()->pull('url.intended'),
            ));
        } catch (Throwable $exception) {
            $sessions->reject($request);
            Log::notice('authentik_oidc_authentication_denied', [
                'exception_class' => $exception::class,
                'source_fingerprint' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            ]);

            return redirect('/login')->withErrors([
                'employee_id' => 'MBFD Identity sign-in could not be completed. Please try again or contact an administrator.',
            ]);
        }
    }
}
