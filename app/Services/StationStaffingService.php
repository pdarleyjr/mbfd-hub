<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Apparatus;
use App\Models\Station;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class StationStaffingService
{
    /**
     * @return array{
     *   known: bool,
     *   station_number: int,
     *   assigned_units: list<string>,
     *   assigned_apparatus_count: ?int,
     *   assigned_personnel_count: ?int,
     *   dorm_beds_count: ?int,
     *   in_service_assigned_count: ?int,
     *   out_of_service_assigned_count: ?int,
     *   maintenance_assigned_count: ?int,
     *   unavailable_assigned_units: list<string>,
     *   unmatched_assigned_units: list<string>,
     *   ambiguous_assigned_units: array<string, list<int>>,
     *   unmapped_station_units: list<string>
     * }
     */
    public function summaryFor(Station $station): array
    {
        /** @var Collection<int, Apparatus> $apparatuses */
        $apparatuses = $station->relationLoaded('apparatuses')
            ? $station->getRelation('apparatuses')
            : $station->apparatuses()->get();

        return $this->buildSummary((int) $station->station_number, $apparatuses);
    }

    /** @return array<string, mixed> */
    public function summaryForStationNumber(int $stationNumber): array
    {
        $station = Station::query()
            ->where('station_number', $stationNumber)
            ->with('apparatuses')
            ->first();

        if ($station === null) {
            return $this->buildSummary($stationNumber, collect());
        }

        /** @var Collection<int, Apparatus> $apparatuses */
        $apparatuses = $station->getRelation('apparatuses');

        return $this->buildSummary($stationNumber, $apparatuses);
    }

    /** @param Collection<int, Apparatus> $apparatuses */
    private function buildSummary(int $stationNumber, Collection $apparatuses): array
    {
        $definition = config("station_operations.stations.{$stationNumber}");

        if (! is_array($definition)) {
            Log::warning('No authoritative staffing definition exists for station.', [
                'station_number' => $stationNumber,
            ]);

            return [
                'known' => false,
                'station_number' => $stationNumber,
                'assigned_units' => [],
                'assigned_apparatus_count' => null,
                'assigned_personnel_count' => null,
                'dorm_beds_count' => null,
                'in_service_assigned_count' => null,
                'out_of_service_assigned_count' => null,
                'maintenance_assigned_count' => null,
                'unavailable_assigned_units' => [],
                'unmatched_assigned_units' => [],
                'ambiguous_assigned_units' => [],
                'unmapped_station_units' => $apparatuses->map(fn (Apparatus $apparatus): string => $this->apparatusLabel($apparatus))->values()->all(),
            ];
        }

        $units = collect($definition['units']);
        $matchedIds = [];
        $unmatched = [];
        $ambiguous = [];
        $unavailable = [];
        $inService = 0;
        $outOfService = 0;
        $maintenance = 0;

        foreach ($units as $unit) {
            $aliases = collect($unit['aliases'] ?? [$unit['label']])
                ->map(fn (string $alias): string => $this->normalize($alias))
                ->unique();

            $matches = $apparatuses->filter(function (Apparatus $apparatus) use ($aliases): bool {
                $candidates = collect([
                    $apparatus->designation,
                    $apparatus->getAttribute('unit_id'),
                    $apparatus->name,
                ])->filter()->map(fn (string $candidate): string => $this->normalize($candidate));

                return $candidates->contains(fn (string $candidate): bool => $aliases->contains($candidate));
            })->values();

            if ($matches->isEmpty()) {
                $unmatched[] = $unit['label'];

                continue;
            }

            if ($matches->count() > 1) {
                $ambiguous[$unit['label']] = $matches->pluck('id')->map(fn ($id): int => (int) $id)->all();

                continue;
            }

            /** @var Apparatus $match */
            $match = $matches->first();
            $matchedIds[] = (int) $match->id;
            $status = $match->getAttribute('status');
            if ($this->isInService(is_string($status) ? $status : null)) {
                $inService++;
            } else {
                $unavailable[] = $unit['label'];
                if ($this->isMaintenance(is_string($status) ? $status : null)) {
                    $maintenance++;
                } elseif ($this->isOutOfService(is_string($status) ? $status : null)) {
                    $outOfService++;
                }
            }
        }

        if ($unmatched !== [] || $ambiguous !== []) {
            Log::warning('Authoritative station complement could not be fully matched to apparatus records.', [
                'station_number' => $stationNumber,
                'unmatched_units' => $unmatched,
                'ambiguous_units' => $ambiguous,
            ]);
        }

        $unmapped = $apparatuses
            ->reject(fn (Apparatus $apparatus): bool => in_array((int) $apparatus->id, $matchedIds, true))
            ->map(fn (Apparatus $apparatus): string => $this->apparatusLabel($apparatus))
            ->values()
            ->all();

        return [
            'known' => true,
            'station_number' => $stationNumber,
            'assigned_units' => $units->pluck('label')->values()->all(),
            'assigned_apparatus_count' => $units->count(),
            'assigned_personnel_count' => $units->sum(fn (array $unit): int => (int) ($unit['personnel'] ?? 0)),
            'dorm_beds_count' => (int) $definition['dorm_beds'],
            'in_service_assigned_count' => $inService,
            'out_of_service_assigned_count' => $outOfService,
            'maintenance_assigned_count' => $maintenance,
            'unavailable_assigned_units' => $unavailable,
            'unmatched_assigned_units' => $unmatched,
            'ambiguous_assigned_units' => $ambiguous,
            'unmapped_station_units' => $unmapped,
        ];
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^A-Z0-9]+/', '', strtoupper(trim($value))) ?? '';
    }

    private function isInService(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['active', 'in service', 'in_service'], true);
    }

    private function isOutOfService(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['out of service', 'out_of_service', 'retired'], true);
    }

    private function isMaintenance(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['maintenance', 'in maintenance'], true);
    }

    private function apparatusLabel(Apparatus $apparatus): string
    {
        return (string) ($apparatus->designation ?: $apparatus->getAttribute('unit_id') ?: $apparatus->name ?: "Apparatus {$apparatus->id}");
    }
}
