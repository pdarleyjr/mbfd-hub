<?php

declare(strict_types=1);

namespace App\Enums;

enum ApparatusServiceTicketCategory: string
{
    case RepairMechanical = 'repair_mechanical';
    case PreventiveMaintenance = 'preventive_maintenance';
    case Electrical = 'electrical';
    case SpecialtySystem = 'specialty_system';
    case Other = 'other';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::RepairMechanical->value => 'Repair / Mechanical',
            self::PreventiveMaintenance->value => 'Preventive Maintenance / Service',
            self::Electrical->value => 'Electrical',
            self::SpecialtySystem->value => 'Pump / Aerial / Specialty System',
            self::Other->value => 'Other',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_keys(self::options());
    }
}
