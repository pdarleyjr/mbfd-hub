<?php

declare(strict_types=1);

namespace App\Models\Training;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class TrainingSession extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['classifications' => 'array', 'held_on' => 'immutable_date', 'starts_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new \LogicException('Training sessions cannot be deleted.'));
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    public function oic(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'oic_employee_profile_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(TrainingAttendance::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TrainingRosterVersion::class);
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(TrainingRosterVersion::class)->latestOfMany('revision');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(TrainingRosterAudit::class);
    }

    public function kioskGrants(): HasMany
    {
        return $this->hasMany(TrainingKioskGrant::class);
    }
}
