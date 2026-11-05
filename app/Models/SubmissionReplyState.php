<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether a member's reply on a submission is waiting for staff (docs/member-replies.md section 4).
 * Written only by App\Services\MemberReplies\MemberReplyLedger; read by dashboards and lists.
 *
 * @property string $subject_type
 * @property int $subject_id
 * @property bool $awaiting_staff_reply
 * @property \Illuminate\Support\Carbon|null $last_member_reply_at
 * @property \Illuminate\Support\Carbon|null $last_staff_reply_at
 */
final class SubmissionReplyState extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'subject_id' => 'integer',
        'awaiting_staff_reply' => 'boolean',
        'last_member_reply_at' => 'datetime',
        'last_staff_reply_at' => 'datetime',
        'last_member_reply_user_id' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function lastMemberReplyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_member_reply_user_id');
    }

    /**
     * Submissions whose newest human message came from a member (oldest wait first when ordered by the caller).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAwaitingStaff(Builder $query): Builder
    {
        return $query->where('awaiting_staff_reply', true);
    }

    /**
     * @param  Builder<static>  $query
     * @param  string|list<string>  $types
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, string|array $types): Builder
    {
        return $query->whereIn('subject_type', (array) $types);
    }
}
