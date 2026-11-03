<?php

declare(strict_types=1);

namespace App\Support\UnitStatus;

/** A PulsePoint designation resolved to one of the 16 units; `confirmed` is false when PulsePoint printed a leading `?`. */
final readonly class UnitMatch
{
    public function __construct(
        public UnitDefinition $unit,
        public bool $confirmed,
    ) {}
}
