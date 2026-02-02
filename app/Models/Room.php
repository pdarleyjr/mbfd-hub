<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        return $query->where('is_active', true);
    }

    /**
     * Scope by room type
