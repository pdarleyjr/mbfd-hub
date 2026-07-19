<?php

namespace App\Services\OperationalForms;

final class FrocImportMergeService
{
    private const LABOR_CAPACITY = 13;

    private const MILEAGE_CAPACITY = 2;

    public function merge(array $data, string $title, array $analysis): array
    {
        $summary = [
            'applied_fields' => [],
            'appended_labor_rows' => [],
            'appended_mileage_rows' => [],
            'updated_mileage_rows' => [],
            'estimated_fields' => [],
            'skipped_conflicts' => [],
            'capacity_warnings' => [],
        ];

        $event = trim((string) ($analysis['event_name'] ?? ''));
        $currentEvent = trim((string) data_get($data, 'general_information.event_id', ''));
        if ($event !== '' && $currentEvent === '') {
            data_set($data, 'general_information.event_id', $event);
            $summary['applied_fields'][] = 'general_information.event_id';
        } elseif ($event !== '' && $currentEvent !== $event) {
            $summary['skipped_conflicts'][] = 'general_information.event_id';
        }

        $date = trim((string) ($analysis['report_date'] ?? ''));
        $currentDate = trim((string) data_get($data, 'general_information.date', ''));
        if ($date !== '' && $currentDate === '') {
            data_set($data, 'general_information.date', $date);
            $summary['applied_fields'][] = 'general_information.date';
        } elseif ($date !== '' && $currentDate !== $date) {
            $summary['skipped_conflicts'][] = 'general_information.date';
        }

        $newTitle = $title;
        if ($event !== '' && preg_match('/^F-ROC Daily Activity Report\s+—/u', $title) === 1) {
            $newTitle = $event.' — '.trim((string) ($analysis['unit_designation'] ?? ''));
            $summary['applied_fields'][] = 'title';
        }

        $mileage = array_values($data['vehicle_mileage'] ?? []);
        foreach ($analysis['vehicle_mileage'] ?? [] as $incoming) {
            $unit = trim((string) ($incoming['equipment_id'] ?? $analysis['unit_designation'] ?? ''));
            $matching = null;
            foreach ($mileage as $index => $existing) {
                if ($unit !== '' && strcasecmp(trim((string) ($existing['equipment_id'] ?? '')), $unit) === 0) {
                    $matching = $index;
                    break;
                }
            }

            if ($matching === null) {
                if (count($mileage) >= self::MILEAGE_CAPACITY) {
                    $summary['capacity_warnings'][] = 'Vehicle mileage capacity reached; one imported row was skipped.';
                    continue;
                }
                $mileage[] = $this->mileageRow($incoming);
                $matching = array_key_last($mileage);
                $summary['appended_mileage_rows'][] = $matching;
                $summary['updated_mileage_rows'][] = $matching;
                continue;
            }

            foreach ($this->mileageRow($incoming) as $field => $value) {
                if ($field === 'event_related') {
                    continue;
                }
                if (filled($value) && blank($mileage[$matching][$field] ?? null)) {
                    $mileage[$matching][$field] = $value;
                    $summary['applied_fields'][] = "vehicle_mileage.$matching.$field";
                } elseif (filled($value) && (string) ($mileage[$matching][$field] ?? '') !== (string) $value) {
                    $summary['skipped_conflicts'][] = "vehicle_mileage.$matching.$field";
                }
            }
            $summary['updated_mileage_rows'][] = $matching;
        }
        $data['vehicle_mileage'] = $mileage;

        $labor = array_values($data['labor'] ?? []);
        foreach ($analysis['labor'] ?? [] as $incoming) {
            if (count($labor) >= self::LABOR_CAPACITY) {
                $summary['capacity_warnings'][] = 'Labor activity capacity reached; remaining imported rows were skipped.';
                break;
            }
            $labor[] = $this->laborRow($incoming);
            $index = array_key_last($labor);
            $summary['appended_labor_rows'][] = $index;
            if ((bool) ($incoming['end_estimated'] ?? false)) {
                $summary['estimated_fields'][] = "labor.$index.end";
            }
        }
        $data['labor'] = $labor;

        return [
            'data' => $data,
            'title' => rtrim($newTitle, " —"),
            'summary' => $summary,
        ];
    }

    private function mileageRow(array $row): array
    {
        return [
            'category' => (string) ($row['category'] ?? ''),
            'equipment_id' => (string) ($row['equipment_id'] ?? ''),
            'operator' => (string) ($row['operator'] ?? ''),
            'destination' => (string) ($row['destination'] ?? ''),
            'start_odometer' => (string) ($row['start_odometer'] ?? ''),
            'end_odometer' => (string) ($row['end_odometer'] ?? ''),
            'manual_miles' => (string) ($row['manual_miles'] ?? ''),
            'correction_reason' => (string) ($row['correction_reason'] ?? ''),
            'event_related' => (bool) ($row['event_related'] ?? true),
        ];
    }

    private function laborRow(array $row): array
    {
        return [
            'category' => (string) ($row['category'] ?? ''),
            'work_performed' => (string) ($row['work_performed'] ?? ''),
            'location_gps' => (string) ($row['location_gps'] ?? ''),
            'start' => (string) ($row['start'] ?? ''),
            'end' => (string) ($row['end'] ?? ''),
            'manual_override_hours' => '',
            'override_reason' => '',
            'event_related' => (bool) ($row['event_related'] ?? true),
        ];
    }
}
