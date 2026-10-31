<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Models\InboundEmail;
use App\Models\OutboundEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Joins a thread to exactly one inbound or outbound row. Cascades away with either. */
final class MailThreadMessage extends Model
{
    protected $fillable = ['thread_id', 'inbound_email_id', 'outbound_email_id', 'message_at'];

    protected function casts(): array
    {
        return ['message_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<MailThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MailThread::class, 'thread_id');
    }

    /** @return BelongsTo<InboundEmail, $this> */
    public function inbound(): BelongsTo
    {
        return $this->belongsTo(InboundEmail::class, 'inbound_email_id');
    }

    /** @return BelongsTo<OutboundEmail, $this> */
    public function outbound(): BelongsTo
    {
        return $this->belongsTo(OutboundEmail::class, 'outbound_email_id');
    }
}
