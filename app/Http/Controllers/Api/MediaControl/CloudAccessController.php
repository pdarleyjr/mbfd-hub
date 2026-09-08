<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\MediaControl;

use App\Models\User;
use App\Services\Oidc\CloudIdentityAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CloudAccessController
{
    public function __invoke(Request $request, CloudIdentityAccess $access): JsonResponse
    {
        $data = $request->validate(['hub_user_id' => ['required', 'integer', 'min:1'], 'client_id' => ['required', 'in:media-control']]);
        $user = User::query()->find($data['hub_user_id']);
        if ($user === null) {
            return response()->json(['error' => 'invalid_identity'], 401);
        }
        $link = $access->forUser($user);

        return response()->json(['issuer' => (string) config('services.media_control.authorization.issuer'),
            'audience' => 'media-control', 'subject' => 'hub-user:'.$user->id, 'user_id' => (int) $user->id,
            'has_cloud_access' => $link !== null, 'nextcloud_uid' => $link?->external_uid], 200, ['Cache-Control' => 'no-store, private']);
    }
}
