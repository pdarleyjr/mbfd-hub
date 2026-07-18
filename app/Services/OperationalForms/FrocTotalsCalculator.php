<?php

namespace App\Services\OperationalForms;

use InvalidArgumentException;

final class FrocTotalsCalculator
{
    public static function calculate(array $data): array
    {
        $labor = [false => 0, true => 0];
        foreach ($data['labor'] ?? [] as $row) {
            $labor[(bool) ($row['event_related'] ?? false)] += self::effectiveLaborHundredths($row);
        }

        $equipment = [false => 0, true => 0];
        foreach ($data['equipment_hours'] ?? [] as $row) {
            $equipment[(bool) ($row['event_related'] ?? false)] += self::decimalToHundredths((string) ($row['hours'] ?? '0'));
        }

        $mileage = [false => 0, true => 0];
        foreach (($data['vehicle_mileage'] ?? $data['equipment_mileage'] ?? []) as $row) {
            $mileage[(bool) ($row['event_related'] ?? false)] += self::mileageHundredths($row);
        }

        return [
            'p2_total_non_event_hours' => self::formatHundredths($labor[false]),
            'p2_total_event_hours' => self::formatHundredths($labor[true]),
            'p3_equipment_hours_total_non_event' => self::formatHundredths($equipment[false]),
            'p3_equipment_hours_total_event' => self::formatHundredths($equipment[true]),
            'p3_mileage_total_non_event' => self::formatHundredths($mileage[false]),
            'p3_mileage_total_event' => self::formatHundredths($mileage[true]),
        ];
    }

    public static function effectiveLaborHours(array $row): string
    {
        return self::formatHundredths(self::effectiveLaborHundredths($row));
    }

    public static function mileage(array $row): string
    {
        return self::formatHundredths(self::mileageHundredths($row));
    }

    private static function effectiveLaborHundredths(array $row): int
    {
        if (filled($row['manual_override_hours'] ?? null)) {
            if (blank($row['override_reason'] ?? null)) {
                throw new InvalidArgumentException('A manual labor-hours override requires a reason.');
            }

            return self::decimalToHundredths((string) $row['manual_override_hours']);
        }

        $start = self::timeToMinutes((string) ($row['start'] ?? '00:00'));
        $end = self::timeToMinutes((string) ($row['end'] ?? '00:00'));
        if ($end < $start) {
            $end += 24 * 60;
        }

        return intdiv((($end - $start) * 100) + 30, 60);
    }

    private static function mileageHundredths(array $row): int
    {
        $start = self::decimalToHundredths((string) ($row['start_odometer'] ?? '0'));
        $end = self::decimalToHundredths((string) ($row['end_odometer'] ?? '0'));
        $difference = $end - $start;

        if ($difference < 0 && blank($row['correction_reason'] ?? null)) {
            throw new InvalidArgumentException('Negative mileage requires a correction reason.');
        }

        return filled($row['manual_miles'] ?? null)
            ? self::decimalToHundredths((string) $row['manual_miles'])
            : $difference;
    }

    private static function timeToMinutes(string $time): int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            throw new InvalidArgumentException('Time must use HH:MM format.');
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours > 23 || $minutes > 59) {
            throw new InvalidArgumentException('Time is outside the valid range.');
        }

        return ($hours * 60) + $minutes;
    }

    private static function decimalToHundredths(string $value): int
    {
        $value = trim($value);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Decimal value is invalid.');
        }

        $fraction = str_pad($matches[3] ?? '', 2, '0');
        $amount = ((int) $matches[2] * 100) + (int) $fraction;

        return ($matches[1] ?? '') === '-' ? -$amount : $amount;
    }

    private static function formatHundredths(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $absolute = abs($value);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
