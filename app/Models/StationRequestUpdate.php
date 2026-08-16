<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StationRequestUpdate extends Model
{
    protected $fillable = [
        'station_request_id',
        'status',
        'public_note',
        'internal_note',
        'changed_by_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Station request updates are append-only.'));
        static::deleting(static fn (): never => throw new LogicException('Station request updates are append-only.'));
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
