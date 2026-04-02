<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrtInventorySession extends Model
{
    protected $fillable = [
        'trailer_id',
        'session_date',
    ];

    protected $casts = [
        'session_date' => 'date',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(TrtInventoryEntry::class, 'session_id');
    }

    /**
     * Find or create a session for today's date.
     * Implements the 24-hour merge window: all submissions on the same
     * calendar day attach to the same session.
     */
    public static function findOrCreateForToday(?int $trailerId = null): self
    {
        return self::firstOrCreate([
            'session_date' => now()->toDateString(),
            'trailer_id' => $trailerId,
        ]);
    }
}
