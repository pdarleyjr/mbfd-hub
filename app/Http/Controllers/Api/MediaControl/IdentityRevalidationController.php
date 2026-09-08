<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\MediaControl;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class IdentityRevalidationController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['hub_user_id' => ['required', 'integer', 'min:1'],
            'media_control_security_version' => ['required', 'integer', 'min:0'],
            'security_version' => ['required', 'integer', 'min:1'], 'member_id' => ['present', 'nullable', 'integer', 'min:1'],
            'client_id' => ['required', 'in:media-control']]);
        $user = User::query()->find($data['hub_user_id']);
        if ($user === null || ! $user->isAuthenticationAllowed() || $user->must_change_password
            || (int) $user->media_control_security_version !== (int) $data['media_control_security_version']
            || (int) $user->security_version !== (int) $data['security_version']
            || ($user->employee_profile_id === null ? null : (int) $user->employee_profile_id) !== ($data['member_id'] === null ? null : (int) $data['member_id'])) {
            return response()->json(['error' => 'invalid_identity'], 401);
        }
        try {
            if (! $user->hasCurrentMediaControlEntitlement()) {
                return response()->json(['error' => 'invalid_identity'], 401);
            }
        } catch (Throwable) {
            return response()->json(['error' => 'authorization_unavailable'], 503);
        }

        return response()->json(['issuer' => (string) config('services.media_control.authorization.issuer'),
            'audience' => 'media-control', 'subject' => 'hub-user:'.$user->id, 'user_id' => (int) $user->id,
            'security_version' => (int) $user->security_version, 'member_id' => $user->employee_profile_id === null ? null : (int) $user->employee_profile_id,
            'media_control_security_version' => (int) $user->media_control_security_version,
            'role' => app(\App\Services\Security\ApplicationRoleResolver::class)->forUser($user, 'media_control')], 200, ['Cache-Control' => 'no-store, private']);
    }
}
