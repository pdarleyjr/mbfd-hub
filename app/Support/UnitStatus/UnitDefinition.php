<?php

declare(strict_types=1);

namespace App\Support\UnitStatus;

final readonly class UnitDefinition
{
    public const RESCUE = 'rescue';

    public const COMBAT = 'combat';

    /** Counted as rescue on a medical call and as combat on any other call. */
    public const BY_CALL = 'by_call';

    /** @param  list<string>  $designations  Normalized PulsePoint designations (A-Z0-9 only) */
    public function __construct(
        public string $code,
        public string $name,
        public int $station,
        public array $designations,
        public string $rosterLabel,
        public string $class = self::BY_CALL,
    ) {}

    public function domId(): string
    {
        return 'unit-'.strtolower($this->code);
    }
}
