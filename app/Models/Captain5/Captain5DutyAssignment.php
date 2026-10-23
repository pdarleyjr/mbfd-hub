<?php

declare(strict_types=1);

namespace App\Models\Captain5;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A controlled out-of-class (acting) Captain 5 assignment for one duty day.
 * Rows are never deleted; only the revoke columns may change, once.
 *
 * @property int $id
 * @property int $employee_profile_id
 * @property \Carbon\CarbonImmutable $duty_date
 * @property \Carbon\CarbonImmutable $starts_at
 * @property \Carbon\CarbonImmutable $ends_at
 * @property string|null $shift_letter
 * @property string $reason
 * @property int $assigned_by_user_id
 * @property \Carbon\CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property string|null $revocation_reason
 */
final class Captain5DutyAssignment extends Model
{
    protected $table = 'captain5_duty_assignments';

    protected $guarded = ['id'];

    private const MUTABLE = ['revoked_at', 'revoked_by_user_id', 'revocation_reason', 'updated_at'];

    protected $casts = [
        'duty_date' => 'immutable_date',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $assignment): void {
            if ($assignment->getOriginal('revoked_at') !== null) {
                throw new LogicException('A revoked Captain 5 assignment cannot change.');
            }
            if (array_diff(array_keys($assignment->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Only revocation may change a Captain 5 assignment.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('Captain 5 assignments are never deleted.'));
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /** @param Builder<self> $query */
    public function scopeCovering(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->whereNull('revoked_at')->where('starts_at', '<=', $moment)->where('ends_at', '>', $moment);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }
}
