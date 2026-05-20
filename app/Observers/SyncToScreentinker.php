<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors MBFD admin users into the ScreenTinker database whenever an admin's
 * password is created or changed. Hooks the User `saved` model event.
 *
 * Plaintext is captured by App\Casts\HashedAndCaptured (the User model's
 * password cast) onto a transient property on the model. We read it here
 * AFTER save commits, then POST {email, password, name} to the ScreenTinker
 * sync endpoint with a bearer token.
 *
 * Failures are logged and never block the user save — sync is best-effort.
 */
final class SyncToScreentinker
{
    /**
     * Spatie roles that count as MBFD admin and qualify for ScreenTinker
     * platform_admin mirroring. Workgroup-only and training-only roles are
     * intentionally excluded (they don't manage signage).
     */
    private const MIRRORED_ROLES = [
        'super_admin',
        'admin',
        'logistics_admin',
        'training_admin',
    ];

    public function saved(User $user): void
    {
        $plaintext = $user->_screentinker_plaintext_password ?? null;
        if (! is_string($plaintext) || $plaintext === '') {
            return;
        }
        // Always clear so the property doesn't leak into subsequent saves.
        unset($user->_screentinker_plaintext_password);

        if (! $user->hasAnyRole(self::MIRRORED_ROLES)) {
            return;
        }

        $url = config('services.screentinker.sync_url');
        $token = config('services.screentinker.sync_token');
        if (! $url || ! $token) {
            Log::warning('SyncToScreentinker: SCREENTINKER_SYNC_URL or SCREENTINKER_SYNC_TOKEN missing; skipping', [
                'user_email' => $user->email,
            ]);
            return;
        }

        try {
            $response = Http::timeout(5)
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'email' => $user->email,
                    'password' => $plaintext,
                    'name' => $user->display_name ?: $user->name,
                    'role' => 'platform_admin',
                ]);

            if (! $response->successful()) {
                Log::warning('SyncToScreentinker non-2xx', [
                    'user_email' => $user->email,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return;
            }

            Log::info('SyncToScreentinker ok', [
                'user_email' => $user->email,
                'action' => $response->json('action'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SyncToScreentinker exception', [
                'user_email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
