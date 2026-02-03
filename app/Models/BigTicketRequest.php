<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BigTicketRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'station_id',
        'room_type',
        'room_label',
        'items',
        'other_item',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'items' => 'array',
    ];

    /**
     * Get the station that owns the request.
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Get the user who created the request.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get display label for room type.
     */
    public function getRoomLabelAttribute(): string
    {
        return $this->room_label ?? ucwords(str_replace('_', ' ', $this->room_type));
    }
}