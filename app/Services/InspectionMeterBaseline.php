<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Apparatus;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

final class InspectionMeterBaseline
{
    public function issue(Apparatus $apparatus): string
    {
        return Crypt::encryptString(json_encode([
            'apparatus_id' => $apparatus->id,
            'engine_hours' => $apparatus->current_engine_hours,
            'miles' => $apparatus->current_miles,
            'issued_at' => now()->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The readings the form loaded with, or null when the token is unusable. A token
     * older than daily_checkout.meter_baseline_max_age_hours (or one without an issue
     * time) is unverified: a stale token must not excuse a lower reading.
     *
     * @return array{engine_hours: mixed, miles: mixed}|null
     */
    public function read(?string $token, int $apparatusId): ?array
    {
        if ($token === null) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        return is_array($data) && ($data['apparatus_id'] ?? null) === $apparatusId
            && array_key_exists('engine_hours', $data) && array_key_exists('miles', $data)
            && $this->isFresh($data['issued_at'] ?? null)
            ? ['engine_hours' => $data['engine_hours'], 'miles' => $data['miles']] : null;
    }

    private function isFresh(mixed $issuedAt): bool
    {
        if (! is_int($issuedAt)) {
            return false;
        }
        $maxAgeHours = (int) config('daily_checkout.meter_baseline_max_age_hours', 168);

        return now()->getTimestamp() - $issuedAt <= $maxAgeHours * 3600;
    }
}
