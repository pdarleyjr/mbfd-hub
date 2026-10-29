<?php

declare(strict_types=1);

namespace App\Models\EmployeeMessaging;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One member's private conversation with the Logistics team (docs/employee-logistics-messaging.md).
 *
 * Ownership is the canonical users.id in member_user_id; the member_* columns are a snapshot so
 * history still reads correctly after a rename, promotion or station move. Every column is
 * server-controlled: nothing here is mass assignable from a request.
 *
 * @property int $id
 * @property int|null $member_user_id
 * @property int|null $employee_profile_id
 * @property string $member_name
 * @property string|null $member_employee_number
 * @property string|null $member_rank
 * @property string|null $member_station
 * @property string|null $subject
 * @property string|null $last_message_preview
 * @property string|null $last_message_side
 * @property \Illuminate\Support\Carbon|null $last_message_at
 * @property int|null $last_member_message_id
 * @property int|null $last_logistics_message_id
 * @property int|null $member_read_message_id
 * @property int|null $logistics_read_message_id
 * @property int|null $logistics_read_by_user_id
 * @property \Illuminate\Support\Carbon|null $logistics_read_at
 * @property int $message_count
 */
class EmployeeLogisticsThread extends Model
{
    protected $table = 'employee_logistics_threads';

    protected $guarded = ['*'];

    protected $casts = [
        'member_user_id' => 'integer',
        'employee_profile_id' => 'integer',
        'last_message_at' => 'datetime',
        'last_member_message_id' => 'integer',
        'last_logistics_message_id' => 'integer',
        'member_read_message_id' => 'integer',
        'logistics_read_message_id' => 'integer',
        'logistics_read_by_user_id' => 'integer',
        'logistics_read_at' => 'datetime',
        'message_count' => 'integer',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_user_id');
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_profile_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmployeeLogisticsMessage::class, 'thread_id');
    }

    /** Logistics has replied since the member last opened this conversation. */
    public function isUnreadForMember(): bool
    {
        return $this->last_logistics_message_id !== null
            && $this->last_logistics_message_id > (int) $this->member_read_message_id;
    }

    /** The member has written since anyone on the Logistics team last opened or answered it. */
    public function isUnreadForLogistics(): bool
    {
        return $this->last_member_message_id !== null
            && $this->last_member_message_id > (int) $this->logistics_read_message_id;
    }

    /** Short label for lists: the subject, else the start of the newest message. */
    public function title(): string
    {
        $subject = trim((string) $this->subject);

        return $subject !== '' ? $subject : 'Message to Logistics';
    }

    /** @param Builder<self> $query */
    public function scopeUnreadForMember(Builder $query): void
    {
        $query->whereNotNull('last_logistics_message_id')
            ->where(fn (Builder $q) => $q->whereNull('member_read_message_id')
                ->orWhereColumn('last_logistics_message_id', '>', 'member_read_message_id'));
    }

    /** @param Builder<self> $query */
    public function scopeUnreadForLogistics(Builder $query): void
    {
        $query->whereNotNull('last_member_message_id')
            ->where(fn (Builder $q) => $q->whereNull('logistics_read_message_id')
                ->orWhereColumn('last_member_message_id', '>', 'logistics_read_message_id'));
    }
}
