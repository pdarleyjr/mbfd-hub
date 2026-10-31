<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Models\OutboundEmail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A private draft. Never an outbound_emails row. `version` rises on every save so two tabs cannot silently
 * overwrite each other; the send path moves open -> sending under a row lock so a double click sends once.
 *
 * @property list<string> $to
 * @property list<string> $cc
 * @property list<string> $bcc
 */
final class MailDraft extends Model
{
    public const MODE_NEW = 'new';

    public const MODE_REPLY = 'reply';

    public const MODE_REPLY_ALL = 'reply_all';

    public const MODE_FORWARD = 'forward';

    public const MODES = [self::MODE_NEW, self::MODE_REPLY, self::MODE_REPLY_ALL, self::MODE_FORWARD];

    public const STATE_OPEN = 'open';

    public const STATE_SENDING = 'sending';

    public const STATE_SENT = 'sent';

    public const STATE_UNCONFIRMED = 'unconfirmed';

    protected $fillable = [
        'user_id', 'thread_id', 'mode', 'source_kind', 'source_id', 'to', 'cc', 'bcc', 'subject',
        'body_html', 'version', 'state', 'outbound_email_id',
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'version' => 'integer',
            'source_id' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<MailThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MailThread::class, 'thread_id');
    }

    /** @return BelongsTo<OutboundEmail, $this> */
    public function outbound(): BelongsTo
    {
        return $this->belongsTo(OutboundEmail::class, 'outbound_email_id');
    }

    /** @return HasMany<MailDraftAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MailDraftAttachment::class, 'draft_id')->orderBy('id');
    }

    /** The id the send ledger carries as source_id, so the existing duplicate guards see one stable key. */
    public function ledgerSourceId(): string
    {
        return 'draft:'.$this->getKey();
    }
}
