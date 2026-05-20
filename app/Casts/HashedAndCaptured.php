<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use WeakMap;

/**
 * Drop-in replacement for Laravel's built-in `'hashed'` cast that ALSO stores
 * the plaintext value on a sidecar `WeakMap<Model, string>` so observers
 * running after the cast can mirror plaintext to external systems.
 *
 * Why a WeakMap instead of a model dynamic property: Eloquent's `__set` magic
 * intercepts any property assignment and routes it through `setAttribute()`,
 * which would (a) trigger another cast lookup, (b) put the value in
 * `$this->attributes`, and (c) try to persist it as a database column — none
 * of which we want. WeakMap entries are keyed by the model object identity
 * and are automatically released when the model goes out of scope, so we
 * get request-scoped storage with zero leak risk.
 *
 * Behavior matches the built-in hashed cast: if the incoming value already
 * looks like a bcrypt hash (`$2y$...`), it's passed through untouched.
 */
final class HashedAndCaptured implements CastsAttributes
{
    /** @var WeakMap<Model, string>|null */
    private static ?WeakMap $captured = null;

    /**
     * Sidecar storage for captured plaintext passwords keyed by model identity.
     * Public so observers and tests can read it. WeakMap-typed so entries are
     * auto-released when the model object is garbage collected.
     */
    public static function captured(): WeakMap
    {
        return self::$captured ??= new WeakMap();
    }

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

        self::captured()[$model] = $value;

        return [$key => Hash::make($value)];
    }

    private function looksLikeBcryptHash(string $value): bool
    {
        return (bool) preg_match('/^\$2[axyb]\$\d+\$/', $value);
    }
}
