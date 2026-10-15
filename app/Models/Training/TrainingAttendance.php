<?php

declare(strict_types=1);

namespace App\Models\Training;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TrainingAttendance extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'signature' => 'array', 'signed_at' => 'immutable_datetime', 'voided_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new \LogicException('Training attendance cannot be deleted; use an audited void.'));
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_profile_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(TrainingRosterAudit::class, 'training_attendance_id');
    }
}
