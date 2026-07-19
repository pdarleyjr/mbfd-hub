<?php

declare(strict_types=1);

namespace App\Services\OperationalForms;

final class FrocDropdownOptions
{
    public const CATEGORIES = ['A', 'B', 'N/A'];

    public const CATEGORY_A = [
        'Debris Removal - Public Right-of-Way',
        'Debris Removal - *Hazardous Leaner/Hanger',
        'Debris Removal - *Hazardous Stump',
        'Debris Removal - *Hazardous Materials',
        'Debris Removal - *Sediment Removal',
        'Debris Removal - Public Property',
        'Debris Removal - Waterways',
        'Debris Removal - Private Non-Commercial Property',
        'Debris Removal - Commercial Property',
        'Debris Removal - Private Roads',
        'Debris Removal - Other (Please Specify)',
        'Monitoring - Compiling Documentation',
        'Monitoring - Training Debris Monitor',
        'Monitoring - Field Supervisory Oversight',
        'Monitoring - Contracted Debris Removal (Load Site)',
        'Monitoring - Contracted Debris Removal (Disposal Site)',
        'Monitoring - Public Right-of-Way',
        'Monitoring - Hazardous Leaner/Hanger',
        'Monitoring - Hazardous Stump',
        'Monitoring - Hazardous Materials',
        'Monitoring - Sediment Removal',
        'Monitoring - Public Property',
        'Monitoring - Waterways',
        'Monitoring - Private Non-Commercial Property',
        'Monitoring - Commercial Property',
        'Monitoring - Private Roads',
        'Monitoring - Other (Please Specify)',
    ];

    public const CATEGORY_B = [
        'EPM - Pre-Positioning Equipment and Resources',
        'EPM - *Safety Inspections',
        'EPM - Search and Rescue',
        'EPM - Search and Rescue (Mission-Related Standby Time)',
        'EPM - Flood Fighting (Emergency Pumping or Sandbagging)',
        'EPM - Emergency Access',
        'EPM - Debris First Push',
        'EPM - Supplies/Commodities (Purchase or Distribution)',
        'EPM - Medical Care and Transport (Event Related)',
        'EPM - Medical Triage and Necessary Tests',
        'EPM - Medical Treatment, Stabilization, Monitoring',
        'EPM - Shelter Support Operations',
        'EPM - *Demolition of Structures',
        'EPM - *Firefighting (Suppression)',
        'EPM - *Firefighting (Protection)',
        'EPM - Firefighting (Mission-Related Standby Time)',
        'EPM - *Temporary Facilities (Essential Service Relocation)',
        'EPM - Generator Refueling',
        'EPM - *Dissemination of Information (Please Provide Info/Memo Being Disseminated)',
        'EPM - EOC Operations',
        'EPM - EOC Operations ESF-04 (Firefighting)',
        'EPM - EOC Operations ESF-07 (Resource Support)',
        'EPM - EOC Operations ESF-08 (Health & Medical)',
        'EPM - EOC Operations ESF-09 (Search & Rescue)',
        'EPM - EOC Operations ESF-10 (Environmental Protection)',
        'EPM - EOC Operations ESF-14 (Public Information)',
        'EPM - Temporary Facility Setup, Operations, or Demobilization',
        'EPM - Mass Mortuary Services',
        'EPM - Temporary Emergency Repairs',
        'EPM - *HAZMAT Field Operations (Handling or Disposal)',
        'EPM - Incident Management Team (IMT) Operations',
        'EPM - Other (Please Specify)',
    ];

    /** @return array<string, array<int, string>> */
    public static function descriptions(): array
    {
        return [
            'A' => self::CATEGORY_A,
            'B' => self::CATEGORY_B,
            'N/A' => [...self::CATEGORY_A, ...self::CATEGORY_B],
        ];
    }

    /** @return array{categories: array<int, string>, descriptions_by_category: array<string, array<int, string>>, description_allows_custom: bool} */
    public static function toArray(): array
    {
        return [
            'categories' => self::CATEGORIES,
            'descriptions_by_category' => self::descriptions(),
            'description_allows_custom' => true,
        ];
    }
}
