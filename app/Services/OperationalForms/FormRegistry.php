<?php

namespace App\Services\OperationalForms;

use InvalidArgumentException;

final class FormRegistry
{
    public function get(string $formType): FormDefinition
    {
        return match ($formType) {
            'ics_214' => new FormDefinition(
                resource_path('forms/ics-214/1.0'),
                ['resources' => 8, 'activities' => 24],
            ),
            'froc_log_001_ff' => new FormDefinition(
                resource_path('forms/froc-log-001-ff/11'),
                [
                    'team_members' => 14,
                    'labor' => 13,
                    'equipment_hours' => 6,
                    'vehicle_mileage' => 2,
                    'materials' => 7,
                    'additional_notes' => 28,
                ],
            ),
            default => throw new InvalidArgumentException('Unsupported operational form type.'),
        };
    }

    public function formTypes(): array
    {
        return array_map(function (string $type): array {
            $definition = $this->get($type);
            $manifest = $definition->manifest();

            return [
                'form_type' => $type,
                'form_version' => $manifest['form_version'],
                'display_name' => $manifest['display_name'],
                'capacities' => $definition->capacities(),
            ];
        }, ['ics_214', 'froc_log_001_ff']);
    }
}
