<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PublicPersonnelEquipmentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PersonnelRequest $personnelRequest */
        $personnelRequest = $this->resource;

        return [
            'id' => $personnelRequest->id,
            'public_id' => $personnelRequest->public_id,
            'request_number' => $personnelRequest->request_number,
            'status' => $personnelRequest->status,
            'item_count' => $personnelRequest->items_count ?? $personnelRequest->items->count(),
            'items' => $this->whenLoaded('items', fn (): array => $personnelRequest->items
                ->map(fn (PersonnelRequestItem $item): array => [
                    'id' => $item->id,
                    'item_name' => $item->item_name,
                    'quantity' => $item->quantity,
                    'reason' => $item->reason,
                ])
                ->all()),
            'created_at' => $personnelRequest->created_at,
            'updated_at' => $personnelRequest->updated_at,
        ];
    }
}
