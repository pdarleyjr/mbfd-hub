<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

final class CanonicalActivationIntent
{
    public const SESSION_KEY = 'auth.canonical_activation_intent';

    public function issue(Session $session, Employee $employee, CarbonInterface $at): void
    {
        $session->put(self::SESSION_KEY, [
            'employee_profile_id' => $employee->id,
            'expires_at' => $at->getTimestamp() + max(60, (int) config('security.identity_recovery.intent_seconds', 600)),
            'session_binding' => Str::random(64),
            'nonce_hash' => null,
        ]);
    }

    public function present(Session $session, CarbonInterface $at): ?string
    {
        $intent = $this->current($session, $at);
        if ($intent === null) {
            return null;
        }

        $nonce = Str::random(64);
        $intent['nonce_hash'] = $this->hashNonce((string) $intent['session_binding'], $nonce);
        $session->put(self::SESSION_KEY, $intent);

        return $nonce;
    }

    public function consumeNonce(Session $session, string $nonce, CarbonInterface $at): ?int
    {
        $intent = $this->current($session, $at);
        if ($intent === null || ! is_string($intent['nonce_hash'] ?? null)) {
            return null;
        }

        $expected = $intent['nonce_hash'];
        $intent['nonce_hash'] = null;
        $session->put(self::SESSION_KEY, $intent);

        if ($nonce === '' || ! hash_equals($expected, $this->hashNonce((string) $intent['session_binding'], $nonce))) {
            return null;
        }

        return (int) $intent['employee_profile_id'];
    }

    public function invalidate(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /** @return array<string, int|string|null>|null */
    private function current(Session $session, CarbonInterface $at): ?array
    {
        $intent = $session->get(self::SESSION_KEY);
        if (! is_array($intent)
            || ! is_int($intent['employee_profile_id'] ?? null)
            || ! is_int($intent['expires_at'] ?? null)
            || ! is_string($intent['session_binding'] ?? null)
            || $intent['expires_at'] <= $at->getTimestamp()) {
            $this->invalidate($session);

            return null;
        }

        return $intent;
    }

    private function hashNonce(string $sessionBinding, string $nonce): string
    {
        return hash_hmac('sha256', $sessionBinding.'|'.$nonce, (string) config('app.key'));
    }
}
