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
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{engine_hours: mixed, miles: mixed}|null */
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
            ? ['engine_hours' => $data['engine_hours'], 'miles' => $data['miles']] : null;
    }
}
