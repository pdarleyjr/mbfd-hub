<?php

declare(strict_types=1);

namespace App\Services\Identity;

final class EmployeeIdentifier
{
    public static function normalize(string $value): string
    {
        // Copying from a roster can add non-breaking boundary spaces. Never
        // remove internal characters or leading zeroes from an identity.
        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value) ?? trim($value);
    }
}
