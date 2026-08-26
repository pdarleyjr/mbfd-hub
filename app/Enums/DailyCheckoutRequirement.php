<?php

declare(strict_types=1);

namespace App\Enums;

enum DailyCheckoutRequirement: string
{
    case Required = 'required';
    case Exempt = 'exempt';
    case Reserve = 'reserve';
    case Administrative = 'administrative';
    case Inactive = 'inactive';
    case Unknown = 'unknown';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Required->value => 'Required',
            self::Exempt->value => 'Exempt',
            self::Reserve->value => 'Reserve',
            self::Administrative->value => 'Administrative',
            self::Inactive->value => 'Inactive',
            self::Unknown->value => 'Unknown - needs policy confirmation',
        ];
    }
}
