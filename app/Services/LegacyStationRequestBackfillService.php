<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\LegacyStationRequestBackfillResult;
use App\Models\BigTicketRequest;
use App\Models\Employee;
use App\Models\FireEquipmentRequest;
use App\Models\Room;
use App\Models\StationRequest;
use App\Models\User;
use App\Support\Security\Base64Image;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyStationRequestBackfillService
{
    private int $created = 0;

    private int $skipped = 0;

    public function run(): LegacyStationRequestBackfillResult
    {
        $this->created = 0;
        $this->skipped = 0;

        if (Schema::hasTable('big_ticket_requests')) {
            BigTicketRequest::query()->with('creator')->orderBy('id')->eachById(
                fn (BigTicketRequest $legacy) => $this->backfillBigTicket($legacy),
            );
        }

        if (Schema::hasTable('fire_equipment_requests')) {
            FireEquipmentRequest::query()->with('requestedBy')->orderBy('id')->eachById(
                fn (FireEquipmentRequest $legacy) => $this->backfillFireEquipment($legacy),
            );
        }

        return new LegacyStationRequestBackfillResult($this->created, $this->skipped);
    }

    private function backfillBigTicket(BigTicketRequest $legacy): void
    {
        $source = 'big_ticket_requests';
        if ($this->alreadyBackfilled($source, (int) $legacy->id)) {
            $this->skipped++;

            return;
        }

        [$room, $roomMatch] = $this->resolveRoom(
            (int) $legacy->station_id,
            $legacy->room_label,
            $legacy->room_type,
        );
        $creator = $legacy->creator;
        $creator = $creator instanceof User ? $creator : null;
        [$employee, $requesterName] = $this->resolveEmployee($creator, $creator?->name);
        $legacyItems = collect($legacy->items ?? [])->filter(fn ($item): bool => filled($item))->values();
        if (filled($legacy->other_item)) {
            $legacyItems->push($legacy->other_item);
        }

        $this->persistLegacy(
            source: $source,
            legacy: $legacy,
            attributes: [
                'station_id' => $legacy->station_id,
                'room_id' => $room?->id,
                'room_name_snapshot' => $room?->name ?: (filled($legacy->room_label) ? trim((string) $legacy->room_label) : null),
                'requested_by_employee_id' => $employee?->id,
                'requester_name_snapshot' => $requesterName,
                'request_type' => 'repair_service',
                'subject_type' => $legacy->room_type,
                'title' => filled($legacy->room_label)
                    ? "{$legacy->room_label} repair / replacement request"
                    : str($legacy->room_type)->replace('_', ' ')->title()->append(' request')->toString(),
                'description' => $legacy->notes ?: 'Imported legacy big ticket request.',
                'priority' => 'normal',
                'status' => 'pending',
                'metadata' => [
                    'legacy' => [
                        'source' => $source,
                        'id' => $legacy->id,
                        'room_type' => $legacy->room_type,
                        'room_label' => $legacy->room_label,
                        'room_match' => $roomMatch,
                        'items' => $legacy->items,
                        'other_item' => $legacy->other_item,
                        'notes' => $legacy->notes,
                        'created_by_user_id' => $legacy->created_by,
                    ],
                ],
            ],
            items: $legacyItems->map(fn (string $item): array => [
                'item_name' => $item,
                'category' => $legacy->room_type,
                'quantity' => 1,
                'reason' => 'Legacy big ticket request',
            ])->all(),
        );
    }

    private function backfillFireEquipment(FireEquipmentRequest $legacy): void
    {
        $source = 'fire_equipment_requests';
        if ($this->alreadyBackfilled($source, (int) $legacy->id)) {
            $this->skipped++;

            return;
        }

        $requestedBy = $legacy->requestedBy;
        $requestedBy = $requestedBy instanceof User ? $requestedBy : null;
        [$employee, $requesterName] = $this->resolveEmployee(
            $requestedBy,
            $legacy->requested_by_name ?: $requestedBy?->name,
        );
        $legacyStatus = (string) ($legacy->status ?: 'pending');
        $status = $this->mapStatus($legacyStatus);
        $formData = is_array($legacy->form_data) ? $legacy->form_data : [];
        $items = collect($formData['items'] ?? [])->map(function (array $item, int $index) use ($legacy): array {
            $photoPath = $this->preserveLegacyImage(
                $item['photo'] ?? null,
                'station-requests/legacy/photos',
                "fire-equipment-{$legacy->id}-item-{$index}",
            );

            return [
                'item_name' => $item['description'] ?? $legacy->equipment_type,
                'category' => $legacy->equipment_type,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'reason' => $item['reason'] ?? null,
                'pd_case_number' => $item['pd_case_number'] ?? $legacy->pd_case_number,
                'photo_path' => $photoPath,
                'metadata' => [
                    'legacy_item' => collect($item)->except('photo')->all(),
                    'legacy_photo_present' => filled($item['photo'] ?? null),
                ],
            ];
        });
        if ($items->isEmpty()) {
            $items->push([
                'item_name' => $legacy->equipment_type,
                'category' => $legacy->equipment_type,
                'quantity' => 1,
                'reason' => null,
                'pd_case_number' => $legacy->pd_case_number,
                'photo_path' => null,
                'metadata' => [
                    'legacy_item' => [],
                    'legacy_photo_present' => false,
                ],
            ]);
        }
        $sanitizedFormData = $formData;
        foreach ($sanitizedFormData['items'] ?? [] as $index => $item) {
            unset($sanitizedFormData['items'][$index]['photo']);
            $sanitizedFormData['items'][$index]['photo_path'] = $items->get($index)['photo_path'] ?? null;
        }
        $signaturePaths = array_filter([
            'member' => $this->preserveLegacyImage(
                $legacy->signature,
                'station-requests/legacy/signatures',
                "fire-equipment-{$legacy->id}-member",
            ),
            'officer' => $this->preserveLegacyImage(
                $legacy->officer_signature,
                'station-requests/legacy/signatures',
                "fire-equipment-{$legacy->id}-officer",
            ),
        ]);

        $timestamps = $this->terminalTimestamps($status, $legacy->updated_at ?? $legacy->created_at);
        $this->persistLegacy(
            source: $source,
            legacy: $legacy,
            attributes: array_merge([
                'station_id' => $legacy->station_id,
                'room_id' => null,
                'room_name_snapshot' => null,
                'requested_by_employee_id' => $employee?->id,
                'requester_name_snapshot' => $requesterName,
                'request_type' => 'equipment',
                'subject_type' => $legacy->equipment_type,
                'title' => $legacy->equipment_type,
                'description' => $legacy->explanation ?: $legacy->description,
                'priority' => $this->mapPriority((string) $legacy->priority),
                'status' => $status,
                'current_public_response' => $legacy->notes,
                'acknowledged_at' => $status === 'acknowledged' ? ($legacy->updated_at ?? $legacy->created_at) : null,
                'metadata' => [
                    'signatures' => $signaturePaths,
                    'legacy' => [
                        'source' => $source,
                        'id' => $legacy->id,
                        'status' => $legacyStatus,
                        'equipment_type' => $legacy->equipment_type,
                        'description' => $legacy->description,
                        'explanation' => $legacy->explanation,
                        'form_data' => $sanitizedFormData,
                        'signature_present' => filled($legacy->signature),
                        'officer_signature_present' => filled($legacy->officer_signature),
                        'pd_case_number' => $legacy->pd_case_number,
                        'approved_by_user_id' => $legacy->approved_by,
                        'approved_at' => optional($legacy->approved_at)?->toIso8601String(),
                        'notes' => $legacy->notes,
                    ],
                ],
            ], $timestamps),
            items: $items->all(),
        );
    }

    /** @param array<string, mixed> $attributes @param list<array<string, mixed>> $items */
    private function persistLegacy(string $source, Model $legacy, array $attributes, array $items): void
    {
        DB::transaction(function () use ($source, $legacy, $attributes, $items): void {
            $legacyId = (int) $legacy->getKey();
            $legacyCreatedAt = $legacy->getAttribute('created_at') ?? now();
            $legacyUpdatedAt = $legacy->getAttribute('updated_at') ?? $legacyCreatedAt;

            if ($this->alreadyBackfilled($source, $legacyId)) {
                $this->skipped++;

                return;
            }

            /** @var StationRequest $request */
            $request = StationRequest::query()->forceCreate(array_merge($attributes, [
                'legacy_source' => $source,
                'legacy_id' => $legacyId,
                'created_at' => $legacyCreatedAt,
                'updated_at' => $legacyUpdatedAt,
            ]));
            $request->items()->createMany($items);
            $request->updates()->create([
                'status' => $request->status,
                'public_note' => $request->current_public_response,
                'metadata' => [
                    'event' => 'legacy_import',
                    'legacy_source' => $source,
                    'legacy_id' => $legacyId,
                    'legacy_status' => data_get($request->metadata, 'legacy.status'),
                ],
                'created_at' => $legacyCreatedAt,
                'updated_at' => $legacyCreatedAt,
            ]);
            $this->created++;
        });
    }

    private function alreadyBackfilled(string $source, int $legacyId): bool
    {
        return StationRequest::query()
            ->where('legacy_source', $source)
            ->where('legacy_id', $legacyId)
            ->exists();
    }

    private function preserveLegacyImage(mixed $value, string $directory, string $prefix): ?string
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        $value = trim($value);
        if (str_starts_with(strtolower($value), 'data:image/')) {
            return Base64Image::store($value, $directory, $prefix);
        }

        if (strlen($value) <= 255 && ! str_contains($value, '://') && ! str_starts_with($value, '//')) {
            return ltrim(str_replace('\\', '/', $value), '/');
        }

        return null;
    }

    /** @return array{0: ?Room, 1: string} */
    private function resolveRoom(int $stationId, ?string $label, ?string $type): array
    {
        if (filled($label)) {
            $rooms = Room::query()
                ->where('station_id', $stationId)
                ->whereRaw('LOWER(name) = ?', [strtolower(trim((string) $label))])
                ->get();
            if ($rooms->count() === 1) {
                return [$rooms->first(), 'exact_name'];
            }
            if ($rooms->count() > 1) {
                return [null, 'ambiguous'];
            }
        }

        if (filled($type)) {
            $rooms = Room::query()->where('station_id', $stationId)->ofType((string) $type)->get();
            if ($rooms->count() === 1) {
                return [$rooms->first(), 'type'];
            }
            if ($rooms->count() > 1) {
                return [null, 'ambiguous'];
            }
        }

        return [null, 'none'];
    }

    /** @return array{0: ?Employee, 1: string} */
    private function resolveEmployee(?User $user, ?string $fallbackName): array
    {
        $employee = null;
        if (filled($user?->employee_id)) {
            $employee = Employee::query()->where('employee_id', $user->employee_id)->first();
        }

        $name = trim((string) ($fallbackName ?: $user?->name ?: 'Legacy requester'));
        if ($employee === null && $name !== '') {
            $matches = Employee::query()->whereRaw('LOWER(name) = ?', [strtolower($name)])->limit(2)->get();
            $employee = $matches->count() === 1 ? $matches->first() : null;
        }

        return [$employee, $employee?->name ?: ($name !== '' ? $name : 'Legacy requester')];
    }

    private function mapStatus(string $legacyStatus): string
    {
        return match (strtolower(trim($legacyStatus))) {
            'approved', 'support_services_approved' => 'approved',
            'shift_chief_approved' => 'acknowledged',
            'fulfilled', 'completed' => 'completed',
            'denied', 'rejected' => 'denied',
            default => 'pending',
        };
    }

    private function mapPriority(string $priority): string
    {
        return match (strtolower(trim($priority))) {
            'critical', 'emergency' => 'critical',
            'high' => 'high',
            'low' => 'low',
            default => 'normal',
        };
    }

    /** @return array<string, mixed> */
    private function terminalTimestamps(string $status, mixed $at): array
    {
        return match ($status) {
            'completed' => ['completed_at' => $at],
            'denied' => ['denied_at' => $at],
            'cancelled' => ['cancelled_at' => $at],
            default => [],
        };
    }
}
