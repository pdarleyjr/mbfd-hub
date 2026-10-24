<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\UnitMasterVehicle;

/** Result of looking for the master vehicle record that belongs to an apparatus row. */
final readonly class MasterLinkResolution
{
    public const CODE_LINKED = 'linked';

    public const CODE_NO_NUMBER = 'no_number';

    public const CODE_NOT_FOUND = 'not_found';

    public const CODE_AMBIGUOUS = 'ambiguous';

    public const CODE_VIN_CONFLICT = 'vin_conflict';

    public const CODE_ALREADY_LINKED = 'already_linked';

    private function __construct(
        public string $code,
        public ?UnitMasterVehicle $master,
        public ?string $reason,
    ) {}

    public static function linked(UnitMasterVehicle $master): self
    {
        return new self(self::CODE_LINKED, $master, null);
    }

    public static function needsReview(string $code, string $reason): self
    {
        return new self($code, null, $reason);
    }

    public function isLinked(): bool
    {
        return $this->code === self::CODE_LINKED;
    }
}
