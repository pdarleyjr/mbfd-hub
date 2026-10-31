<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One conversation. Holds grouping and shared human state only; message bodies stay in
 * inbound_emails and outbound_emails.
 *
 * @property list<array{address: string, name: ?string}>|null $participants
 * @property CarbonImmutable|null $last_inbound_at
 * @property CarbonImmutable|null $unread_baseline_at
 * @property-read int|string|null $user_read_through_at Per-user columns added by MailboxQuery
 */
final class MailThread extends Model
{
    public const KIND_CONVERSATION = 'conversation';

    public const KIND_AUTOMATED = 'automated';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    protected $fillable = [
        'thread_key', 'kind', 'subject', 'snippet', 'participants', 'message_count', 'has_attachments',
        'first_message_at', 'last_message_at', 'last_inbound_at', 'unread_baseline_at', 'archived_at',
        'trashed_at', 'snoozed_until', 'assigned_user_id', 'priority',
    ];

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'message_count' => 'integer',
            'has_attachments' => 'boolean',
            'first_message_at' => 'immutable_datetime',
            'last_message_at' => 'immutable_datetime',
            'last_inbound_at' => 'immutable_datetime',
            'unread_baseline_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'trashed_at' => 'immutable_datetime',
            'snoozed_until' => 'immutable_datetime',
        ];
    }

    public function isAutomated(): bool
    {
        return $this->kind === self::KIND_AUTOMATED;
    }

    public function isTrashed(): bool
    {
        return $this->trashed_at !== null;
    }

    /** @return HasMany<MailThreadMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(MailThreadMessage::class, 'thread_id')->orderBy('message_at')->orderBy('id');
    }

    /** @return HasMany<MailThreadUserState, $this> */
    public function userStates(): HasMany
    {
        return $this->hasMany(MailThreadUserState::class, 'thread_id');
    }

    /** @return BelongsToMany<MailLabel, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(MailLabel::class, 'mail_thread_labels', 'thread_id', 'label_id')->withTimestamps();
    }

    /** @return HasMany<MailThreadNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(MailThreadNote::class, 'thread_id')->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<MailThreadEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(MailThreadEvent::class, 'thread_id')->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<MailDraft, $this> */
    public function drafts(): HasMany
    {
        return $this->hasMany(MailDraft::class, 'thread_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
