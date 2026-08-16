<?php

declare(strict_types=1);

namespace App\Enums;

enum StationRequestType: string
{
    case RepairService = 'repair_service';
    case Equipment = 'equipment';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::RepairService => 'Repair / Service',
            self::Equipment => 'Equipment',
        };
    }
}
