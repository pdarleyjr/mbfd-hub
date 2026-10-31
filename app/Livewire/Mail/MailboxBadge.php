<?php

declare(strict_types=1);

namespace App\Livewire\Mail;

use App\Models\User;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailboxQuery;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * The envelope in the admin header: a link to the Email Center with the member's unread count. It counts for
 * the signed-in member only (the shared mailbox has per-person read state) and polls only while visible.
 * The page raises mail-unread-changed when a conversation is opened or changed, so the number drops at once.
 */
final class MailboxBadge extends Component
{
    public function hydrate(): void
    {
        if (! $this->allowed()) {
            abort(403);
        }
    }

    #[On('mail-unread-changed')]
    public function refreshCount(): void
    {
        // Re-render only: render() reads the count.
    }

    public function render(): View
    {
        $user = auth()->user();
        $count = 0;
        $allowed = $user instanceof User && $this->allowed();
        if ($allowed) {
            try {
                $count = app(MailboxQuery::class)->unreadCount($user);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return view('livewire.mail.mailbox-badge', [
            'allowed' => $allowed,
            'count' => $count,
            'url' => \App\Filament\Admin\Pages\EmailCenter::getUrl(),
            'label' => $count > 0 ? 'Email, '.$count.' unread' : 'Email',
        ]);
    }

    private function allowed(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(MailAccess::class)->canView($user);
    }
}
