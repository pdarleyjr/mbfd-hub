<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationRequestItem extends Model
{
    protected $fillable = [
        'station_request_id',
        'room_asset_id',
        'item_name',
        'category',
        'quantity',
        'reason',
        'requested_action',
        'condition',
        'serial_number',
        'manufacturer',
        'model_number',
        'pd_case_number',
        'photo_path',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function stationRequest(): BelongsTo
    {
        return $this->belongsTo(StationRequest::class);
    }

    public function roomAsset(): BelongsTo
    {
        return $this->belongsTo(RoomAsset::class);
    }
}
