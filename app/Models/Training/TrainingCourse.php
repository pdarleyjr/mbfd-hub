<?php

declare(strict_types=1);

namespace App\Models\Training;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TrainingCourse extends Model
{
    public const CLASSIFICATIONS = ['Task Level/Company', 'Officer Training', 'ICS / NIMS / FFOG', 'Driver Training', 'Special Ops', 'RIT/MD/Survival', 'Hazmat Training', 'Extrication', 'Marine Training', 'Water Rescue', 'Fire Boat Operation', 'Night Drill', 'SIMM Training', 'EMS Training', 'Pre-Fire/Prevention', 'Equipment Inservice'];

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['classifications' => 'array'];
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new \LogicException('Training courses cannot be deleted.'));
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }
}
