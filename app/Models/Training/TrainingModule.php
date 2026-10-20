<?php

declare(strict_types=1);

namespace App\Models\Training;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property array{ranks?: list<string>, shifts?: list<string>}|null $audience */
final class TrainingModule extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'sort_order' => 'integer', 'audience' => 'array'];
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new \LogicException('Training modules cannot be deleted.'));
        self::updating(function (self $module): void {
            if ($module->isDirty('training_course_id')) {
                throw new \LogicException('Training modules cannot be moved between courses.');
            }
        });
    }

    /** @return BelongsTo<TrainingCourse, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    /** @return HasMany<TrainingSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }
}
