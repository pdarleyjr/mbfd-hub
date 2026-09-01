<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bid;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Bid\AuthorizeBidRequest;
use App\Models\Employee;
use App\Services\Bid\BidAuthorizationCodeBroker;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use Illuminate\Http\RedirectResponse;

final class AuthorizationController extends Controller
{
    public function __invoke(
        AuthorizeBidRequest $request,
        AuthenticatedMemberContextResolver $contexts,
        BidAuthorizationCodeBroker $codes,
    ): RedirectResponse {
        $validated = $request->validated();
        $context = $contexts->resolve($request);
        $employee = $context->employee();

        if (! $employee instanceof Employee) {
            return $this->callback($validated['redirect_uri'], [
                'error' => 'access_denied',
                'state' => $validated['state'],
            ]);
        }

        $code = $codes->issue(
            $context->user(),
            $employee,
            $validated['client_id'],
            $validated['redirect_uri'],
        );

        return $this->callback($validated['redirect_uri'], [
            'code' => $code,
            'state' => $validated['state'],
        ]);
    }

    /** @param array<string, string> $query */
    private function callback(string $redirectUri, array $query): RedirectResponse
    {
        $response = redirect()->away($redirectUri.'?'.http_build_query($query));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
