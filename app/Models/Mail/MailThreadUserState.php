<?php

declare(strict_types=1);

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One member's private view of a thread: where they have read to, and whether they starred it. */
final class MailThreadUserState extends Model
{
    protected $fillable = ['user_id', 'thread_id', 'read_through_at', 'starred_at', 'marked_unread_at'];

    protected function casts(): array
    {
        return [
            'read_through_at' => 'immutable_datetime',
            'starred_at' => 'immutable_datetime',
            'marked_unread_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<MailThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MailThread::class, 'thread_id');
    }
}
