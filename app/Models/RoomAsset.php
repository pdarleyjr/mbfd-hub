<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'asset_tag',
        'name',
        'description',
        'category',
        'quantity',
        'unit',
        'condition',
        'purchase_date',
        'purchase_price',
        'serial_number',
        'manufacturer',
        'model_number',
        'location_within_room',
        'is_active',
        'notes',
        'retired_at',
        'replaced_by_room_asset_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'purchase_date' => 'date',
        'purchase_price' => 'decimal:2',
        'is_active' => 'boolean',
        'retired_at' => 'datetime',
    ];

    /**
     * Get the room that owns this asset
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Get the station through room
     */
    public function station(): BelongsTo
    {
        return $this->room->belongsTo(Station::class);
    }

    /**
     * Get all audit items for this asset
     */
    public function auditItems(): HasMany
    {
        return $this->hasMany(RoomAuditItem::class);
    }

    public function requestItems(): HasMany
    {
        return $this->hasMany(StationRequestItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(RoomAssetEvent::class)->latest('event_at');
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_room_asset_id');
    }

    /**
     * Scope for active assets
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by category
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope by condition
     */
    public function scopeByCondition($query, string $condition)
    {
        return $query->where('condition', $condition);
    }
}
