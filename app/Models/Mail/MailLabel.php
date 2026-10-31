<?php

declare(strict_types=1);

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class MailLabel extends Model
{
    protected $fillable = ['name', 'color', 'created_by_user_id'];

    /** @return BelongsToMany<MailThread, $this> */
    public function threads(): BelongsToMany
    {
        return $this->belongsToMany(MailThread::class, 'mail_thread_labels', 'label_id', 'thread_id')->withTimestamps();
    }
}
