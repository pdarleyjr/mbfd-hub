<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\Apparatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every physical vehicle ID the fleet knows, frontline and reserve, for the
 * Daily Checkout "confirm the physical vehicle" dropdown and for the swap
 * review screens. Only the ID, its unit and the role are ever exposed.
 */
final class FleetVehicleCatalog
{
    public const ROLE_FRONTLINE = 'frontline';

    public const ROLE_RESERVE = 'reserve';

    public const ROLE_OUT_OF_SERVICE = 'out_of_service';

    /** @return list<array{vehicle_number: string, designation: string|null, role: string}> */
    public function all(): array
    {
        $seen = [];
        $vehicles = [];
        $rows = Apparatus::query()
            ->whereNotNull('vehicle_number')
            ->where('vehicle_number', '!=', '')
            ->orderBy('designation')
            ->orderBy('id')
            ->get(['id', 'designation', 'name', 'vehicle_number', 'status']);

        foreach ($rows as $row) {
            $number = VehicleNumber::normalize($row->vehicle_number);
            $key = $number === null ? null : strtolower($number);
            if ($number === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $vehicles[] = [
                'vehicle_number' => $number,
                'designation' => $row->designation ?: $row->name,
                'role' => self::roleFor($row->getAttribute('status')),
            ];
        }

        usort($vehicles, static fn (array $a, array $b): int => strnatcasecmp($a['vehicle_number'], $b['vehicle_number']));

        return $vehicles;
    }

    public static function roleFor(?string $status): string
    {
        return match (str_replace([' ', '-'], '_', strtolower(trim((string) $status)))) {
            'reserve' => self::ROLE_RESERVE,
            'out_of_service', 'in_repair' => self::ROLE_OUT_OF_SERVICE,
            default => self::ROLE_FRONTLINE,
        };
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            self::ROLE_RESERVE => 'Reserve',
            self::ROLE_OUT_OF_SERVICE => 'Out of service',
            default => 'Frontline',
        };
    }

    /**
     * Units currently carrying this vehicle ID, optionally leaving one unit out.
     *
     * @return Builder<Apparatus>
     */
    public function holders(string $vehicleNumber, ?int $exceptApparatusId = null): Builder
    {
        $query = Apparatus::query()->whereRaw('LOWER(vehicle_number) = ?', [strtolower(VehicleNumber::normalize($vehicleNumber) ?? '')]);
        if ($exceptApparatusId !== null) {
            $query->where('id', '!=', $exceptApparatusId);
        }

        return $query->orderBy('id');
    }

    /** @return Collection<int, Apparatus> */
    public function otherHolders(string $vehicleNumber, int $exceptApparatusId): Collection
    {
        return $this->holders($vehicleNumber, $exceptApparatusId)->get();
    }
}
