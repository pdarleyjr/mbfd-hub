<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal note. No code path may read this model while it builds an outbound message; a test reads the
 * mail services' source to prove none does.
 */
final class MailThreadNote extends Model
{
    protected $fillable = ['thread_id', 'user_id', 'body'];

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
}
