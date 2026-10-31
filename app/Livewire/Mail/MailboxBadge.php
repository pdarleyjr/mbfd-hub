<?php

declare(strict_types=1);

namespace App\Livewire\Mail;

use App\Filament\Admin\Pages\EmailCenter;
use App\Models\User;
use App\Services\Mail\MailAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

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
        $allowed = $user instanceof User && $this->allowed();
        $count = $allowed ? EmailCenter::unreadThisRequest($user) : 0;

        return view('livewire.mail.mailbox-badge', [
            'allowed' => $allowed,
            'count' => $count,
            'url' => EmailCenter::getUrl(),
            'label' => $count > 0 ? 'Email, '.$count.' unread' : 'Email',
        ]);
    }

    private function allowed(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(MailAccess::class)->canView($user);
    }
}
