<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Admin\Pages\EmailCenter;
use App\Models\Mail\MailThread;
use App\Models\Mail\MailThreadMessage;
use App\Models\User;
use App\Services\Mail\MailAccess;

/**
 * For the old Inbound and Outbound message pages: a message the Email Center has indexed is shown there, in its
 * conversation. A message that has not been indexed (or a visitor the Email Center would refuse) keeps the old
 * page, so an old link never dead-ends.
 */
trait RedirectsToMailThread
{
    /** @param  'inbound'|'outbound'  $kind */
    protected function redirectToIndexedThread(string $kind, int|string $id): void
    {
        $user = auth()->user();
        if (! $user instanceof User || ! app(MailAccess::class)->canView($user)) {
            return;
        }
        $membership = MailThreadMessage::query()->where($kind === 'inbound' ? 'inbound_email_id' : 'outbound_email_id', $id)->first();
        $thread = $membership === null ? null : MailThread::query()->find($membership->thread_id);
        if ($thread === null) {
            return;
        }
        $folder = $thread->isAutomated() ? 'automated' : ($kind === 'outbound' ? 'sent' : 'inbox');
        $this->redirect(EmailCenter::getUrl(['folder' => $folder, 'thread' => $thread->getKey()]), navigate: false);
    }
}
