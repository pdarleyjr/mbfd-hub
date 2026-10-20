<?php

declare(strict_types=1);

namespace App\Models\Training;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property list<string> $classifications
 * @property \Carbon\CarbonImmutable $held_on
 * @property \Carbon\CarbonImmutable|null $starts_at
 * @property \Carbon\CarbonImmutable|null $submitted_at
 * @property int $revision
 * @property-read TrainingCourse $course
 * @property-read TrainingRosterVersion|null $latestVersion
 */
final class TrainingSession extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['classifications' => 'array', 'held_on' => 'immutable_date', 'starts_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'revision' => 'integer', 'training_module_id' => 'integer'];
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new \LogicException('Training sessions cannot be deleted.'));
    }

    /** @return BelongsTo<TrainingCourse, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    /** @return BelongsTo<TrainingModule, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(TrainingModule::class, 'training_module_id');
    }

    public function isPaper(): bool
    {
        return $this->attendance_source === 'paper' || $this->status === 'paper';
    }

    /** @return BelongsTo<Employee, $this> */
    public function oic(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'oic_employee_profile_id');
    }

    /** @return HasMany<TrainingAttendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(TrainingAttendance::class);
    }

    /** @return HasMany<TrainingRosterVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TrainingRosterVersion::class);
    }

    /** @return HasOne<TrainingRosterVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(TrainingRosterVersion::class)->latestOfMany('revision');
    }

    /** @return HasMany<TrainingRosterAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(TrainingRosterAudit::class);
    }

    /** @return HasMany<TrainingKioskGrant, $this> */
    public function kioskGrants(): HasMany
    {
        return $this->hasMany(TrainingKioskGrant::class);
    }
}
