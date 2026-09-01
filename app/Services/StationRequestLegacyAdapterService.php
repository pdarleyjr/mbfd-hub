<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\StationRequestSubmissionResult;
use App\Models\Room;
use App\Models\Station;
use App\Services\Identity\AuthenticatedActor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StationRequestLegacyAdapterService
{
    public function __construct(private readonly StationRequestSubmissionService $submissions) {}

    /** @param array<string, mixed> $data */
    public function submitBigTicket(array $data, AuthenticatedActor $actor): StationRequestSubmissionResult
    {
        $employee = $actor->requireEmployee();
        $room = $this->unambiguousRoom(
            (int) $data['station_id'],
            $data['room_label'] ?? null,
            $data['room_type'] ?? null,
        );
        $items = collect($data['items'])->map(fn (string $item): array => [
            'item_name' => $item,
            'category' => $data['room_type'],
            'quantity' => 1,
            'reason' => 'Repair',
            'requested_action' => 'inspect',
        ]);
        if (filled($data['other_item'] ?? null)) {
            $items->push([
                'item_name' => $data['other_item'],
                'category' => $data['room_type'],
                'quantity' => 1,
                'reason' => 'Repair',
                'requested_action' => 'inspect',
            ]);
        }

        return $this->submissions->submit([
            'client_submission_id' => $data['client_submission_id'] ?? (string) Str::uuid(),
            'station_id' => (int) $data['station_id'],
            'room_id' => $room?->id,
            'room_name_snapshot' => $room?->name ?: (filled($data['room_label'] ?? null) ? trim((string) $data['room_label']) : null),
            'requested_by_employee_id' => $employee->id,
            'requester_name_snapshot' => $employee->name,
            'request_type' => 'repair_service',
            'subject_type' => $data['room_type'],
            'title' => filled($data['room_label'] ?? null)
                ? "{$data['room_label']} repair / replacement request"
                : str($data['room_type'])->replace('_', ' ')->title()->append(' request')->toString(),
            'description' => $data['notes'] ?? 'Legacy big ticket request.',
            'priority' => 'normal',
            'items' => $items->values()->all(),
            '_source' => 'legacy_big_ticket_api',
            '_metadata' => ['legacy_payload' => $data],
        ], $actor);
    }

    /** @param array<string, mixed> $data */
    public function submitFireEquipment(array $data, AuthenticatedActor $actor): StationRequestSubmissionResult
    {
        $employee = $actor->requireEmployee();
        $station = $this->resolveStation($data);
        $rawItems = $data['items'] ?? data_get($data, 'form_data.items');
        if (! is_array($rawItems) || $rawItems === []) {
            $rawItems = [[
                'description' => $data['equipment_type'] ?? 'Equipment item',
                'quantity' => 1,
                'reason' => 'Needed',
                'pd_case_number' => $data['pd_case_number'] ?? null,
            ]];
        }
        $items = collect($rawItems)->map(fn (array $item): array => [
            'item_name' => $item['item_name'] ?? $item['description'] ?? $data['equipment_type'] ?? 'Equipment item',
            'category' => $item['category'] ?? $data['equipment_type'] ?? 'equipment',
            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            'reason' => $item['reason'] ?? 'Needed',
            'pd_case_number' => $item['pd_case_number'] ?? $data['pd_case_number'] ?? null,
            'photo' => $item['photo'] ?? null,
        ])->all();
        foreach ($items as $index => $item) {
            if ($item['reason'] === 'Stolen' && blank($item['pd_case_number'])) {
                throw ValidationException::withMessages([
                    "items.{$index}.pd_case_number" => 'A police case number is required for stolen equipment.',
                ]);
            }
        }

        $description = $data['explanation'] ?? $data['description'] ?? 'Legacy fire equipment request.';
        $legacyPayload = collect($data)
            ->except(['member_signature', 'officer_signature', 'signature'])
            ->all();
        foreach ($legacyPayload['items'] ?? [] as $index => $item) {
            unset($legacyPayload['items'][$index]['photo']);
        }
        foreach (data_get($legacyPayload, 'form_data.items', []) as $index => $item) {
            unset($legacyPayload['form_data']['items'][$index]['photo']);
        }

        return $this->submissions->submit([
            'client_submission_id' => $data['client_submission_id'] ?? (string) Str::uuid(),
            'station_id' => $station->id,
            'room_id' => null,
            'room_name_snapshot' => null,
            'requested_by_employee_id' => $employee->id,
            'requester_name_snapshot' => $employee->name,
            'request_type' => 'equipment',
            'subject_type' => $data['equipment_type'] ?? 'equipment',
            'title' => $data['equipment_type'] ?? (count($items) === 1 ? $items[0]['item_name'] : count($items).' equipment items'),
            'description' => $description,
            'priority' => $this->priority($data['priority'] ?? null, $items),
            'submitted_at' => $data['submitted_at'] ?? $data['date'] ?? null,
            'member_signature' => $data['member_signature'] ?? $data['signature'] ?? null,
            'officer_signature' => $data['officer_signature'] ?? null,
            'items' => $items,
            '_source' => 'legacy_fire_equipment_api',
            '_metadata' => ['legacy_payload' => $legacyPayload],
        ], $actor);
    }

    /** @param array<string, mixed> $data */
    private function resolveStation(array $data): Station
    {
        if (isset($data['station_id'])) {
            return Station::query()->findOrFail($data['station_id']);
        }

        $stationNumber = (string) ($data['station'] ?? '');
        if (preg_match('/^Station\s+(\d+)$/i', $stationNumber, $matches)) {
            $stationNumber = $matches[1];
        }
        $station = Station::query()->where('station_number', $stationNumber)->first();
        if ($station === null) {
            throw ValidationException::withMessages(['station' => "Station not found: {$data['station']}"]);
        }

        return $station;
    }

    private function unambiguousRoom(int $stationId, ?string $label, ?string $type): ?Room
    {
        if (filled($label)) {
            $matches = Room::query()->where('station_id', $stationId)
                ->whereRaw('LOWER(name) = ?', [strtolower(trim((string) $label))])->limit(2)->get();
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }
        if (filled($type)) {
            $matches = Room::query()->where('station_id', $stationId)->ofType((string) $type)->limit(2)->get();
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $items */
    private function priority(mixed $priority, array $items): string
    {
        $normalized = strtolower((string) $priority);
        if (in_array($normalized, ['critical', 'high', 'low'], true)) {
            return $normalized;
        }
        $reasons = collect($items)->pluck('reason');

        return $reasons->contains(fn (string $reason): bool => in_array($reason, ['Lost', 'Stolen'], true))
            ? 'high'
            : ($reasons->contains('Damaged/Broken') ? 'normal' : 'low');
    }
}
