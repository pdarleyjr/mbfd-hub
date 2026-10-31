<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Mail\MailDraft;
use App\Models\Mail\MailThread;
use App\Models\Mail\MailThreadEvent;
use App\Models\Mail\MailThreadNote;
use Illuminate\Support\Collection;

/**
 * Everything the conversation view needs, for one member. Notes are included because this is an internal
 * view; the send path never reads them.
 */
final class MailThreadDetail
{
    /**
     * @param  list<MailMessageView>  $messages  oldest first
     * @param  Collection<int, MailThreadNote>  $notes
     * @param  Collection<int, MailThreadEvent>  $events
     * @param  Collection<int, MailDraft>  $drafts  this member's own open drafts on the thread
     */
    public function __construct(
        public readonly MailThread $thread,
        public readonly array $messages,
        public readonly Collection $notes,
        public readonly Collection $events,
        public readonly Collection $drafts,
        public readonly bool $isUnread,
        public readonly bool $isStarred,
        public readonly bool $canAct,
        public readonly bool $canSend,
        public readonly bool $canManage = false,
    ) {}
}
