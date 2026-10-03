<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasArchive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property \Illuminate\Support\Carbon|null $archived_at
 * @property int|null $archived_by
 * @property string|null $archive_reason
 * @property array<int, array<string, mixed>>|null $archive_history
 * @property-read int $entries_count
 */
class TrtInventorySession extends Model
{
    use HasArchive;

    protected $fillable = [
        'trailer_id',
        'session_date',
    ];

    protected $casts = [
        'archive_history' => 'array',
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
        $now = now();
        $sessionDate = $now->toDateString();

        self::query()->insertOrIgnore([
            'trailer_id' => $trailerId,
            'session_date' => $sessionDate,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $query = self::query()->where('session_date', $sessionDate);

        if ($trailerId === null) {
            $query->whereNull('trailer_id');
        } else {
            $query->where('trailer_id', $trailerId);
        }

        return $query->sole();
    }
}
