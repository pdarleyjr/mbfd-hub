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
 * A request to be shown as the shift's acting Captain 5 or acting 300 (`role`), as `act`, or to end
 * that role's current acting display (`end`), decided by an administrator. Display only: it never
 * grants or limits access. Rows are never deleted; once decided, only `ended_at` /
 * `ended_by_request_id` may change. Each role has its own display: approving a 300 request never
 * touches the Captain 5 display and the other way round.
 *
 * @property int $id
 * @property string $kind
 * @property string $role
 * @property int|null $employee_profile_id
 * @property int $requested_by_user_id
 * @property string|null $duration
 * @property string|null $shift_letter
 * @property \Carbon\CarbonImmutable|null $ends_at
 * @property string $status
 * @property int|null $decided_by_user_id
 * @property \Carbon\CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property \Carbon\CarbonImmutable|null $starts_at
 * @property \Carbon\CarbonImmutable|null $ended_at
 * @property int|null $ended_by_request_id
 * @property string|null $client_request_id
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class Captain5ActingRequest extends Model
{
    public const KIND_ACT = 'act';

    public const KIND_END = 'end';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const DURATIONS = ['12h', '24h', 'custom'];

    public const ROLE_CAPTAIN_5 = 'captain_5';

    public const ROLE_COMMAND_300 = 'command_300';

    public const ROLES = [self::ROLE_CAPTAIN_5, self::ROLE_COMMAND_300];

    /** How each role is named to members ("acting Captain 5", "acting 300"). */
    public const ROLE_LABELS = [self::ROLE_CAPTAIN_5 => 'Captain 5', self::ROLE_COMMAND_300 => '300'];

    protected $table = 'captain5_acting_requests';

    protected $guarded = ['id'];

    protected $casts = [
        'ends_at' => 'immutable_datetime',
        'decided_at' => 'immutable_datetime',
        'starts_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    /** Columns a decided request may still change (it can be ended later by another approval). */
    private const AFTER_DECISION = ['ended_at', 'ended_by_request_id', 'updated_at'];

    protected static function booted(): void
    {
        self::updating(static function (self $request): void {
            if ($request->getOriginal('status') !== self::PENDING
                && array_diff(array_keys($request->getDirty()), self::AFTER_DECISION) !== []) {
                throw new LogicException('A decided acting request is history.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('Acting requests are never deleted.'));
    }

    public static function roleLabel(string $role): string
    {
        return self::ROLE_LABELS[$role] ?? self::ROLE_LABELS[self::ROLE_CAPTAIN_5];
    }

    /**
     * Approved acting display of one role covering the moment.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeShowingAt(Builder $query, \DateTimeInterface $moment, string $role): Builder
    {
        return $query->where('role', $role)->where('kind', self::KIND_ACT)->where('status', self::APPROVED)->whereNull('ended_at')
            ->where('starts_at', '<=', $moment)->where('ends_at', '>', $moment);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
