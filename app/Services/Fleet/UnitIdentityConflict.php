<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * UnitIdentityGuard refused an apparatus save. An admin form or API edit sees an ordinary validation error (this is a
 * ValidationException). A sync or batch writer catches this type, sets the one row aside for Fleet and keeps going.
 */
final class UnitIdentityConflict extends ValidationException
{
    /** Every refusal, in one line, for a log, a report row or a review note. */
    public function summary(): string
    {
        return implode(' ', array_map('strval', Arr::flatten($this->errors())));
    }
}
