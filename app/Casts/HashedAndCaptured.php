<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * Drop-in replacement for Laravel's `'hashed'` cast that ALSO stashes the
 * plaintext value onto the model as a transient property
 * `_screentinker_plaintext_password` so observers running on save events can
 * mirror the plaintext to external systems before it's gone forever.
 *
 * Behavior matches the built-in hashed cast: if the incoming value already
 * looks like a bcrypt hash (`$2y$...`), it's passed through untouched.
 */
final class HashedAndCaptured implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value) || $value === '') {
            return [$key => $value];
        }

        if ($this->looksLikeBcryptHash($value)) {
            return [$key => $value];
        }

        // Stash plaintext onto the model as a public dynamic property.
        // Observers on saved() pull this off and dispatch the mirror call.
        $model->_screentinker_plaintext_password = $value;

        return [$key => Hash::make($value)];
    }

    private function looksLikeBcryptHash(string $value): bool
    {
        return (bool) preg_match('/^\$2[axyb]\$\d+\$/', $value);
    }
}
