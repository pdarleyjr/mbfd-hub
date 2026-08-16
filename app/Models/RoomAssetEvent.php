<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RoomAssetEvent extends Model
{
    protected $fillable = [
        'room_asset_id',
        'station_request_id',
        'event_type',
        'event_at',
        'changed_by_user_id',
        'vendor',
        'cost',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'cost' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Room asset events are append-only.'));
        static::deleting(static fn (): never => throw new LogicException('Room asset events are append-only.'));
    }

    public function roomAsset(): BelongsTo
    {
        return $this->belongsTo(RoomAsset::class);
    }

    public function stationRequest(): BelongsTo
    {
        return $this->belongsTo(StationRequest::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
