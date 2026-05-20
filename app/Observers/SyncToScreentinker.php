<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Events\RoleAttached;

/**
 * Mirrors MBFD admin users into the ScreenTinker user table whenever an
 * admin's password is created or changed.
 *
 * Two hook points cover both flows:
 *  1. User::saved — fires when the User row is created or updated; if the user
 *     already has an admin role at that moment, sync runs immediately.
 *     Typical case: existing admin changes their password.
 *  2. RoleAttached — Spatie event fired after assignRole(); when a NEW user is
 *     created and given a role in the same request, the User::saved event
 *     fires BEFORE the role is attached, so the role check there fails.
 *     This hook catches the post-assignment moment, sees the still-stashed
 *     plaintext (we don't clear unless sync ran), and syncs then.
 *     Typical case: admin creates a brand-new user via Filament with role +
 *     password in the same form submission.
 *
 * Plaintext is captured by App\Casts\HashedAndCaptured onto a transient
 * model property (`_screentinker_plaintext_password`). We only clear it
 * AFTER an actual sync attempt — leaving it set across saved/RoleAttached
 * boundaries within a single request.
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
        $this->trySync($user);
    }

    public function onRoleAttached(RoleAttached $event): void
    {
        if ($event->model instanceof User) {
            $this->trySync($event->model);
        }
    }

    private function trySync(User $user): void
    {
        $plaintext = $user->_screentinker_plaintext_password ?? null;
        if (! is_string($plaintext) || $plaintext === '') {
            return;
        }

        if (! $user->hasAnyRole(self::MIRRORED_ROLES)) {
            // No qualifying role yet — keep plaintext stashed for a later
            // RoleAttached event in this same request.
            return;
        }

        $url = config('services.screentinker.sync_url');
        $token = config('services.screentinker.sync_token');
        if (! $url || ! $token) {
            // Always clear the stash so it doesn't dangle past the sync
            // opportunity; missing config means the mirror is disabled.
            unset($user->_screentinker_plaintext_password);
            Log::warning('SyncToScreentinker: SCREENTINKER_SYNC_URL/TOKEN missing; skipping', [
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
            } else {
                Log::info('SyncToScreentinker ok', [
                    'user_email' => $user->email,
                    'action' => $response->json('action'),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('SyncToScreentinker exception', [
                'user_email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // Clear the stash once we've made an attempt, success or fail.
            // Prevents double-fire on subsequent saves in the same request.
            unset($user->_screentinker_plaintext_password);
        }
    }
}
