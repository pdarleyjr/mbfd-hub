<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\StationSupplyRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PublicStationSupplyRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var StationSupplyRequest $supplyRequest */
        $supplyRequest = $this->resource;

        return [
            'id' => $supplyRequest->id,
            'station_id' => $supplyRequest->station_id,
            'request_text' => $supplyRequest->request_text,
            'status' => $supplyRequest->status,
            'shift' => $supplyRequest->created_by_shift,
            'created_at' => $supplyRequest->created_at,
            'updated_at' => $supplyRequest->updated_at,
        ];
    }
}
