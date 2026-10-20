<?php

declare(strict_types=1);

namespace App\Models\Training;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read TrainingSession $session
 */
final class TrainingPaperRosterImport extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['imported_at' => 'immutable_datetime', 'page_count' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Paper originals are immutable.'));
        self::deleting(fn () => throw new \LogicException('Paper originals must be retained.'));
    }

    /** @return BelongsTo<TrainingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    /** @return HasMany<TrainingPaperAttendance, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TrainingPaperAttendance::class);
    }
}
