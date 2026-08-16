<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StationRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StationRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_submission_id',
        'request_number',
        'station_id',
        'room_id',
        'room_name_snapshot',
        'requested_by_employee_id',
        'requester_name_snapshot',
        'request_type',
        'subject_type',
        'title',
        'description',
        'priority',
        'status',
        'current_public_response',
        'status_detail',
        'assigned_to_user_id',
        'assigned_vendor',
        'acknowledged_by',
        'acknowledged_at',
        'completed_at',
        'denied_at',
        'cancelled_at',
        'legacy_source',
        'legacy_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'acknowledged_at' => 'datetime',
            'completed_at' => 'datetime',
            'denied_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $request): void {
            if ($request->request_number !== null) {
                return;
            }

            $year = ($request->created_at ?? now())->format('Y');
            $request->timestamps = false;
            try {
                $request->forceFill([
                    'request_number' => sprintf('SR-%s-%06d', $year, $request->getKey()),
                ])->saveQuietly();
            } finally {
                $request->timestamps = true;
            }
        });
    }

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function requestedByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by_employee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** @return HasMany<StationRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StationRequestItem::class)->orderBy('id');
    }

    /** @return HasMany<StationRequestUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(StationRequestUpdate::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<RoomAssetEvent, $this> */
    public function assetEvents(): HasMany
    {
        return $this->hasMany(RoomAssetEvent::class)->latest('event_at');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', StationRequestStatus::openValues());
    }

    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', StationRequestStatus::terminalValues());
    }

    public function getIsOpenAttribute(): bool
    {
        return in_array($this->status, StationRequestStatus::openValues(), true);
    }
}
