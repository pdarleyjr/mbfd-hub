<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\StationRequestType;
use App\Models\Room;
use App\Models\RoomAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStationRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_submission_id' => ['required', 'uuid'],
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'room_name_snapshot' => ['nullable', 'string', 'max:255'],
            'requested_by_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'request_type' => ['required', Rule::in(StationRequestType::values())],
            'subject_type' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'critical'])],
            'submitted_at' => ['nullable', 'date'],
            'member_signature' => ['nullable', 'required_if:request_type,equipment', 'string', 'max:7000000'],
            'officer_signature' => ['nullable', 'required_if:request_type,equipment', 'string', 'max:7000000'],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*.room_asset_id' => ['nullable', 'integer', 'exists:room_assets,id'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.category' => ['nullable', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.reason' => ['nullable', Rule::in(['Damaged/Broken', 'Lost', 'Stolen', 'Needed', 'Replacement', 'End of Service Life', 'Repair', 'Service', 'Other'])],
            'items.*.requested_action' => ['nullable', Rule::in(['inspect', 'repair', 'replace', 'service', 'remove'])],
            'items.*.condition' => ['nullable', 'string', 'max:100'],
            'items.*.serial_number' => ['nullable', 'string', 'max:255'],
            'items.*.manufacturer' => ['nullable', 'string', 'max:255'],
            'items.*.model_number' => ['nullable', 'string', 'max:255'],
            'items.*.pd_case_number' => ['nullable', 'string', 'max:100'],
            'items.*.photo' => ['nullable', 'string', 'max:7000000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $stationId = (int) $this->input('station_id');
            $roomId = $this->integer('room_id') ?: null;

            if ($roomId !== null && ! Room::query()->whereKey($roomId)->where('station_id', $stationId)->exists()) {
                $validator->errors()->add('room_id', 'The selected room does not belong to the selected station.');
            }

            foreach ((array) $this->input('items', []) as $index => $item) {
                $assetId = (int) ($item['room_asset_id'] ?? 0);
                if ($assetId > 0) {
                    $validAsset = RoomAsset::query()
                        ->whereKey($assetId)
                        ->whereHas('room', fn ($query) => $query->where('station_id', $stationId))
                        ->when($roomId !== null, fn ($query) => $query->where('room_id', $roomId))
                        ->exists();
                    if (! $validAsset) {
                        $validator->errors()->add("items.{$index}.room_asset_id", 'The selected asset does not belong to the selected station and room.');
                    }
                }

                if (($item['reason'] ?? null) === 'Stolen' && blank($item['pd_case_number'] ?? null)) {
                    $validator->errors()->add("items.{$index}.pd_case_number", 'A police case number is required for stolen equipment.');
                }
            }
        }];
    }
}
