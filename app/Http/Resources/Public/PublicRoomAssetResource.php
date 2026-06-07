<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, redacted view of a RoomAsset for the unauthenticated daily-checkout
 * SPA. Allowlist only: identity + condition/quantity.
 *
 * Never exposes serial numbers, purchase price/date, manufacturer, model
 * numbers, or internal notes.
 */
class PublicRoomAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'name' => $this->name,
            'category' => $this->category,
            'quantity' => $this->quantity,
            'condition' => $this->condition,
        ];
    }
}
