<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\MediaControl;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MediaControl\ExchangeMediaControlAuthorizationCodeRequest;
use App\Models\User;
use App\Services\MediaControl\MediaControlAuthorizationCodeBroker;
use Illuminate\Http\JsonResponse;
use Throwable;

final class AuthorizationCodeExchangeController extends Controller
{
    public function __invoke(
        ExchangeMediaControlAuthorizationCodeRequest $request,
        MediaControlAuthorizationCodeBroker $codes,
    ): JsonResponse {
        $validated = $request->validated();
        $record = $codes->redeem($validated['code'], $validated['client_id'], $validated['redirect_uri']);

        if ($record === null) {
            return response()->json(['error' => 'invalid_authorization_code'], 401);
        }

        $user = User::query()->find($record['user_id']);

        if (! $user instanceof User
            || ! $user->isAuthenticationAllowed()
            || (int) $user->security_version !== $record['security_version']) {
            return response()->json(['error' => 'invalid_authorization_code'], 401);
        }

        try {
            if (! $user->hasCurrentMediaControlEntitlement()) {
                return response()->json(['error' => 'access_denied'], 403);
            }
        } catch (Throwable) {
            return response()->json(['error' => 'authorization_unavailable'], 503);
        }

        $response = response()->json([
            'issuer' => $record['issuer'],
            'audience' => $record['audience'],
            'subject' => 'hub-user:'.$user->getKey(),
            'user_id' => (int) $user->getKey(),
            'display_name' => (string) ($user->display_name ?: $user->name),
            'role' => 'platform_admin',
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
