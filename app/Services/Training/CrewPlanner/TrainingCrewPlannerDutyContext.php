<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use Carbon\CarbonImmutable;

/** Actual daily assignments, independent of published bid seats. */
final class TrainingCrewPlannerDutyContext
{
    public function project(array $contexts, ?array $slot): array
    {
        $relevant = array_filter($contexts, function (array $context) use ($slot): bool {
            if ($slot === null) {
                return true;
            }
            if (empty($context['start_at']) || empty($context['end_at'])) {
                return false;
            }

            return CarbonImmutable::parse($context['start_at'])->lt(CarbonImmutable::parse($slot['end_at']))
                && CarbonImmutable::parse($context['end_at'])->gt(CarbonImmutable::parse($slot['start_at']));
        });
        $stations = $apparatus = $labels = $plainStations = $plainUnits = [];
        foreach ($relevant as $context) {
            $station = (string) ($context['station'] ?? 'Not reported');
            $unit = (string) ($context['apparatus'] ?? 'Not reported');
            $plainStations[] = (string) ($context['station'] ?? '');
            $plainUnits[] = (string) ($context['apparatus'] ?? '');
            $period = ! empty($context['start_at']) && ! empty($context['end_at'])
                ? CarbonImmutable::parse($context['start_at'])->setTimezone(\App\Support\HubTimezone::NAME)->format('m/d H:i').'–'.CarbonImmutable::parse($context['end_at'])->setTimezone(\App\Support\HubTimezone::NAME)->format('m/d H:i')
                : 'time not confirmed';
            // Keep the interval beside each unit, including when a class spans a unit change.
            $stations[] = $station.' ('.$period.')';
            $apparatus[] = $unit.' ('.$period.')';
            $labels[] = $station.' · '.$unit.' · '.$period;
        }

        return [
            'station' => implode('; ', array_unique($stations)) ?: 'Not reported for this classroom time',
            'apparatus' => implode('; ', array_unique($apparatus)) ?: 'Not reported for this classroom time',
            'label' => implode('; ', array_unique($labels)) ?: 'Actual duty assignment requires verification',
            // Printed request columns: just the station(s) and unit(s) worked during the session.
            'station_plain' => implode(' / ', array_unique(array_filter($plainStations))),
            'apparatus_plain' => implode(' / ', array_unique(array_filter($plainUnits))),
        ];
    }
}
