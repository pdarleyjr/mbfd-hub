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
 * Per-employee Captain 5 allow/deny decision. History rows are immutable: a new
 * decision supersedes the current row (only the superseded_* columns change, once).
 *
 * @property int $id
 * @property int $employee_profile_id
 * @property string $effect
 * @property string|null $level
 * @property string $reason
 * @property int $set_by_user_id
 * @property \Carbon\CarbonImmutable|null $superseded_at
 * @property int|null $superseded_by_user_id
 */
final class Captain5AccessOverride extends Model
{
    public const EFFECT_ALLOW = 'allow';

    public const EFFECT_DENY = 'deny';

    public const LEVEL_VIEW = 'view';

    public const LEVEL_ACT = 'act';

    protected $table = 'captain5_access_overrides';

    protected $guarded = ['id'];

    private const MUTABLE = ['superseded_at', 'superseded_by_user_id', 'updated_at'];

    protected $casts = ['superseded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(static function (self $override): void {
            if ($override->getOriginal('superseded_at') !== null
                || array_diff(array_keys($override->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Captain 5 access decisions are immutable history.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('Captain 5 access decisions are never deleted.'));
    }

    /** @param Builder<self> $query */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }
}
