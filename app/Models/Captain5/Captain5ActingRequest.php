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
 * A request to be shown as the shift's acting Captain 5 (`act`) or to end the current acting
 * display (`end`), decided by an administrator. Display only: it never grants or limits access.
 * Rows are never deleted; once decided, only `ended_at` / `ended_by_request_id` may change.
 *
 * @property int $id
 * @property string $kind
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
                throw new LogicException('A decided acting Captain 5 request is history.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('Acting Captain 5 requests are never deleted.'));
    }

    /**
     * Approved acting display covering the moment.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeShowingAt(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->where('kind', self::KIND_ACT)->where('status', self::APPROVED)->whereNull('ended_at')
            ->where('starts_at', '<=', $moment)->where('ends_at', '>', $moment);
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
