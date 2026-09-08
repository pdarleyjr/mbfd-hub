<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class OidcSessionRevoker
{
    public function revoke(User $user, ?string $application = null): void
    {
        DB::transaction(function () use ($user, $application): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $sessions = OidcSession::query()->where('user_id', $user->id)->whereNull('revoked_at')
                ->when($application !== null, fn ($query) => $query->where('application', $application))->orderBy('id')->lockForUpdate()->get();
            DB::table('oauth_access_tokens')->whereIn('id', $sessions->pluck('access_token_id')->filter())->update(['revoked' => true]);
            DB::table('oauth_auth_codes')->whereIn('id', $sessions->pluck('auth_code_id'))->update(['revoked' => true]);
            OidcSession::query()->whereIn('id', $sessions->modelKeys())->update(['revoked_at' => now()]);
        });
    }
}
