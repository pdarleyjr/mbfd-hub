<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'station_id',
        'name',
        'floor',
        'room_type',
        'description',
        'capacity',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'room_type',
    ];

    public function getRoomTypeAttribute($value): ?string
    {
        return $value ?? ($this->attributes['type'] ?? null);
    }

    public function setRoomTypeAttribute($value): void
    {
        $table = $this->getTable();

        if (Schema::hasColumn($table, 'room_type')) {
            $this->attributes['room_type'] = $value;

            return;
        }

        $this->attributes['type'] = $value;
    }

    public function getIsActiveAttribute($value): bool
    {
        $table = $this->getTable();

        if (! Schema::hasColumn($table, 'is_active')) {
            return true;
        }

        return (bool) $value;
    }

    public function setIsActiveAttribute($value): void
    {
        $table = $this->getTable();

        if (! Schema::hasColumn($table, 'is_active')) {
            return;
        }

        $this->attributes['is_active'] = $value;
    }

    /**
     * Get the station that owns this room
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Get all assets in this room
     */
    public function assets(): HasMany
    {
        return $this->hasMany(RoomAsset::class);
    }

    /**
     * Get all audits for this room
     */
    public function audits(): HasMany
    {
        return $this->hasMany(RoomAudit::class);
    }

    /**
     * Get active assets in this room
     */
    public function activeAssets(): HasMany
    {
        return $this->hasMany(RoomAsset::class)->where('is_active', true);
    }

    /**
     * Scope for active rooms
     */
    public function scopeActive($query)
    {
        $table = $this->getTable();

        if (! Schema::hasColumn($table, 'is_active')) {
            return $query;
        }

        return $query->where('is_active', true);
    }

    /**
     * Scope by room type
     */
    public function scopeOfType($query, string $type)
    {
        $table = $this->getTable();
        $column = Schema::hasColumn($table, 'room_type') ? 'room_type' : 'type';

        return $query->where($column, $type);
    }
}
