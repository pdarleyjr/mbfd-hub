<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\MediaControl;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MediaControl\AuthorizeMediaControlRequest;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\MediaControl\MediaControlAuthorizationCodeBroker;
use Illuminate\Http\Response;
use Throwable;

final class AuthorizationController extends Controller
{
    public function __invoke(
        AuthorizeMediaControlRequest $request,
        AuthenticatedMemberContextResolver $contexts,
        MediaControlAuthorizationCodeBroker $codes,
    ): Response {
        $validated = $request->validated();
        $user = $contexts->resolve($request)->user();

        try {
            if (! $user->hasCurrentMediaControlEntitlement()) {
                return $this->callback($validated['redirect_uri'], [
                    'error' => 'access_denied',
                    'state' => $validated['state'],
                ]);
            }
        } catch (Throwable) {
            return $this->callback($validated['redirect_uri'], [
                'error' => 'authorization_unavailable',
                'state' => $validated['state'],
            ]);
        }

        $code = $codes->issue($user, $validated['client_id'], $validated['redirect_uri']);

        return $this->callback($validated['redirect_uri'], [
            'code' => $code,
            'state' => $validated['state'],
        ]);
    }

    /** @param array<string, string> $query */
    private function callback(string $redirectUri, array $query): Response
    {
        // End the same-origin login form navigation before opening Media
        // Control. A cross-origin 302 in that chain is blocked by the login
        // page's strict form-action 'self' policy.
        $response = response()->view('auth.media-control-handoff', [
            'destination' => $redirectUri.'?'.http_build_query($query),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
