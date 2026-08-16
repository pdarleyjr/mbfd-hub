<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Room;
use App\Models\Station;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class StationRoomBlueprintService
{
    /**
     * @return list<array{blueprint_key: string, name: string, type: string, capacity: ?int, sort_order: int}>
     */
    public function definitionsForStationNumber(int $stationNumber): array
    {
        $definition = config("station_operations.stations.{$stationNumber}");

        if (! is_array($definition)) {
            return [];
        }

        /** @var Collection<int, array<string, mixed>> $units */
        $units = collect($definition['units'] ?? []);
        $rooms = collect($this->commonRooms());

        $combatUnits = $units->whereIn('kind', ['engine', 'ladder']);
        $rescueUnits = $units->where('kind', 'rescue');
        $fireboatUnits = $units->where('kind', 'fireboat');
        $singleMemberUnits = $units->where('kind', 'single_member');

        if ($combatUnits->isNotEmpty()) {
            $rooms->push($this->room('dorm.combat_officers', 'Combat officer dorm room', 'dormitory', $combatUnits->sum(fn (array $unit): int => (int) ($unit['officers'] ?? 0)), 100));
            $rooms->push($this->room('dorm.combat_firefighters', 'Combat firefighter dorm room', 'dormitory', $combatUnits->sum(fn (array $unit): int => (int) ($unit['firefighters'] ?? 0)), 110));
        }

        if ($rescueUnits->isNotEmpty()) {
            $rooms->push($this->room('dorm.rescue', 'Rescue dorm room', 'dormitory', $rescueUnits->sum(fn (array $unit): int => (int) ($unit['personnel'] ?? 0)), 120));
        }

        foreach ($singleMemberUnits->values() as $index => $unit) {
            $rooms->push($this->room(
                'dorm.'.Str::slug((string) $unit['label'], '_'),
                (string) ($unit['dorm_name'] ?? ($unit['label'].' dorm room')),
                'dormitory',
                (int) ($unit['personnel'] ?? 0),
                130 + $index,
            ));
        }

        if ($fireboatUnits->isNotEmpty()) {
            $rooms->push($this->room('dorm.fireboat_officers', 'Fireboat officer dorm room', 'dormitory', $fireboatUnits->sum(fn (array $unit): int => (int) ($unit['officers'] ?? 0)), 140));
            $rooms->push($this->room('dorm.fireboat_firefighters', 'Fireboat firefighter dorm room', 'dormitory', $fireboatUnits->sum(fn (array $unit): int => (int) ($unit['firefighters'] ?? 0)), 150));
        }

        foreach ($units->values() as $index => $unit) {
            $kind = (string) ($unit['kind'] ?? 'support');
            $label = (string) ($unit['label'] ?? 'Unit');
            $displayName = (string) ($unit['display_name'] ?? $label);
            $area = match ($kind) {
                'engine', 'ladder' => 'combat_apparatus_bay',
                'rescue' => 'rescue_apparatus_bay',
                'fireboat' => 'fireboat_apparatus_area',
                default => 'support_apparatus_bay',
            };
            $name = $kind === 'fireboat'
                ? "{$displayName} berth / apparatus area"
                : "{$displayName} apparatus bay position";

            $rooms->push($this->room($area.'.'.Str::slug($label, '_'), $name, $area, null, 200 + $index));
        }

        return $rooms->sortBy('sort_order')->values()->all();
    }

    public function sync(Station $station): void
    {
        foreach ($this->definitionsForStationNumber((int) $station->station_number) as $definition) {
            $room = Room::query()
                ->where('station_id', $station->id)
                ->where('blueprint_key', $definition['blueprint_key'])
                ->first();

            $room ??= Room::query()
                ->where('station_id', $station->id)
                ->where('name', $definition['name'])
                ->first();

            $room ??= new Room;
            $room->forceFill([
                'station_id' => $station->id,
                'name' => $definition['name'],
                'type' => $definition['type'],
                'capacity' => $definition['capacity'],
                'blueprint_key' => $definition['blueprint_key'],
                'sort_order' => $definition['sort_order'],
            ])->save();
        }
    }

    public function syncAll(): void
    {
        Station::query()
            ->whereIn('station_number', array_keys((array) config('station_operations.stations', [])))
            ->each(fn (Station $station) => $this->sync($station));
    }

    /** @return list<array{blueprint_key: string, name: string, type: string, capacity: ?int, sort_order: int}> */
    private function commonRooms(): array
    {
        return [
            $this->room('kitchen.main', 'Kitchen', 'kitchen', null, 10),
            $this->room('common_area.tv', 'TV room / common area', 'common_area', null, 20),
            $this->room('office.station', 'Station office / watch office', 'office', null, 30),
            $this->room('restroom.crew', 'Crew bathroom', 'restroom', null, 300),
            $this->room('laundry.main', 'Laundry room', 'laundry', null, 310),
            $this->room('fitness.main', 'Fitness / workout room', 'fitness', null, 320),
            $this->room('storage.general', 'General storage', 'storage', null, 330),
            $this->room('utility.mechanical', 'Utility / mechanical room', 'utility', null, 340),
            $this->room('exterior.grounds', 'Exterior / grounds', 'exterior', null, 350),
        ];
    }

    /** @return array{blueprint_key: string, name: string, type: string, capacity: ?int, sort_order: int} */
    private function room(string $key, string $name, string $type, ?int $capacity, int $sortOrder): array
    {
        return [
            'blueprint_key' => $key,
            'name' => $name,
            'type' => $type,
            'capacity' => $capacity,
            'sort_order' => $sortOrder,
        ];
    }
}
