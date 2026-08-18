<?php

declare(strict_types=1);

namespace App\Enums;

enum ApparatusPmServiceType: string
{
    case Pma = 'PMA';
    case Pmc = 'PMC';
    case ThreeHundredHour = '300-Hour PM';
    case AnnualInspection = 'Annual Inspection';
    case ChassisService = 'Chassis Service';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Pma->value => 'PMA — Oil/Fuel Filters, Engine Oil, Grease',
            self::Pmc->value => 'PMC — Oil/Fuel/Air/Trans Filters, Air Dryer',
            self::ThreeHundredHour->value => self::ThreeHundredHour->value,
            self::AnnualInspection->value => self::AnnualInspection->value,
            self::ChassisService->value => self::ChassisService->value,
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_keys(self::options());
    }
}
