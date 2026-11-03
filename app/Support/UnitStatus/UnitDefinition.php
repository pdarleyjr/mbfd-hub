<?php

declare(strict_types=1);

namespace App\Support\UnitStatus;

final readonly class UnitDefinition
{
    /** @param  list<string>  $designations  Normalized PulsePoint designations (A-Z0-9 only) */
    public function __construct(
        public string $code,
        public string $name,
        public int $station,
        public array $designations,
        public string $rosterLabel,
    ) {}

    public function domId(): string
    {
        return 'unit-'.strtolower($this->code);
    }
}
