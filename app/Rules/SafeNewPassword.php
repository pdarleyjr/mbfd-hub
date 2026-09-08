<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

/** Applies only when accepting a NEW password, never to existing login credentials. */
final class SafeNewPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }
        if (str_contains($value, "\0")) {
            $fail('The :attribute cannot contain NUL characters.');

            return;
        }
        // bcrypt consumes at most 72 bytes, not 72 Unicode characters.
        if (Hash::getDefaultDriver() === 'bcrypt' && strlen($value) > 72) {
            $fail('The :attribute must be at most 72 bytes. Some characters use more than one byte.');
        }
    }
}
