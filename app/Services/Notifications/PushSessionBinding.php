<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\AuthenticationSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ties a push device to the sign-in it was enrolled from, so ending that sign-in
 * (explicit sign-out, or revoking every session) also removes the device.
 *
 * The binding is the sha256 of the canonical session registry id kept in the
 * Laravel session (`auth.canonical_session_id`); the raw id is never stored here.
 * A session that merely times out deletes nothing: the member is still the owner
 * of that browser and sign-in again re-uses the device.
 */
final class PushSessionBinding
{
    public const SESSION_KEY = 'auth.canonical_session_id';

    public static function hash(string $registryId): string
    {
        return hash('sha256', $registryId);
    }

    /** The binding for the request's current sign-in, or null when it has no canonical session. */
    public static function forRequest(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }
        $registryId = $request->session()->get(self::SESSION_KEY);

        return is_string($registryId) && $registryId !== '' ? self::hash($registryId) : null;
    }

    /**
     * Remove the member's devices enrolled from the given ended sessions. Other
     * devices of the same member (other sign-ins, or not bound) are untouched.
     * Never throws: ending a session must not depend on push housekeeping.
     *
     * @param  iterable<string>  $registryIds
     */
    public function detachForSessions(User $user, iterable $registryIds): int
    {
        $bindings = [];
        foreach ($registryIds as $registryId) {
            if ($registryId !== '') {
                $bindings[] = self::hash($registryId);
            }
        }
        if ($bindings === []) {
            return 0;
        }

        try {
            return (int) $user->pushSubscriptions()->whereIn('session_binding', array_values(array_unique($bindings)))->delete();
        } catch (Throwable $exception) {
            Log::warning('Push devices of an ended session could not be removed', [
                'user_id' => $user->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    /** Every session the member has ever had in the registry (security actions end all of them). */
    public function detachForAllSessions(User $user): int
    {
        return $this->detachForSessions(
            $user,
            AuthenticationSession::query()->where('user_id', $user->getKey())->pluck('id')->map(fn ($id): string => (string) $id),
        );
    }
}
