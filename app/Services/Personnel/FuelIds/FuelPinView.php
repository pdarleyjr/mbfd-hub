<?php

declare(strict_types=1);

namespace App\Services\Personnel\FuelIds;

use Carbon\CarbonInterface;

/**
 * An employee's current Fuel PIN for display. Never serialize it into JSON, caches or data attributes.
 */
final readonly class FuelPinView
{
    public const MASK = '••••';

    public function __construct(
        public string $pin,
        public string $matchStatus,
        public ?string $sourceStatus,
        public ?string $department,
        public string $sourceName,
        public ?CarbonInterface $importedAt,
        public bool $employeeDeparted,
        public int $rowId,
    ) {}

    public function masked(): string
    {
        return self::MASK;
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new \LogicException('A Fuel PIN view cannot be serialized.');
    }
}
