<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Normalizes and digests the low-entropy activation attributes. Digests are
 * keyed with a secret derived from the application key and bound to one
 * personnel record, so equal birthdays or phones never produce equal rows.
 */
final class MemberActivationVerifierDigest
{
    private const CONTEXT = 'mbfd-member-activation-verifier-v1';

    public function dateOfBirth(int $employeeProfileId, CarbonImmutable $dateOfBirth): string
    {
        return $this->digest('dob', $employeeProfileId, $dateOfBirth->format('Y-m-d'));
    }

    public function phone(int $employeeProfileId, string $normalizedPhone): string
    {
        return $this->digest('phone', $employeeProfileId, $normalizedPhone);
    }

    /** Ten-digit North American number, or null when the value cannot be one. */
    public function normalizePhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    public function parseDateOfBirth(int $year, int $month, int $day, CarbonImmutable $today): ?CarbonImmutable
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }
        $date = CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');

        return $date instanceof CarbonImmutable
            && $date->year >= 1900
            && $date->lessThanOrEqualTo($today->subYears(16))
            ? $date : null;
    }

    private function digest(string $attribute, int $employeeProfileId, string $value): string
    {
        return hash_hmac('sha256', implode("\0", [$attribute, $employeeProfileId, $value]), $this->key());
    }

    private function key(): string
    {
        $appKey = (string) config('app.key');
        $material = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if (! is_string($material) || strlen($material) < 16) {
            throw new RuntimeException('The application key required for activation verifiers is unavailable.');
        }

        return hash_hkdf('sha256', $material, 32, self::CONTEXT);
    }
}
