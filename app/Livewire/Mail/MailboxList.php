<?php

declare(strict_types=1);

namespace App\Livewire\Mail;

use App\Models\User;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailActionRefused;
use App\Services\Mail\MailboxFilter;
use App\Services\Mail\MailboxQuery;
use App\Services\Mail\MailComposer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Throwable;

/**
 * The middle column: one folder as a list of conversations (or, in Drafts, the member's own drafts), 30 at a
 * time with a cursor. It only reads. Opening a row asks the page to open it; the page owns the address bar.
 * The filters arrive as plain props and the page rebuilds this component when they change, so the cursor trail
 * always belongs to the filters that produced it. It polls on its own, apart from the composer, so a refresh
 * of the list can never touch what someone is typing.
 */
final class MailboxList extends Component
{
    private const PER_PAGE = 30;

    private const MAX_PAGES = 10;

    public string $folder = MailboxFilter::INBOX;

    public string $search = '';

    public string $unread = '';

    public string $attachments = '';

    public string $label = '';

    public string $priority = '';

    #[Reactive]
    public ?int $selected = null;

    /** How many pages of 30 are shown. Each is fetched with its own cursor, newest first. */
    public int $pages = 1;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    public function open(int $id): void
    {
        $this->authorizeAccess();
        $this->dispatch('mail-open-thread', id: $id);
    }

    public function openDraft(int $id): void
    {
        $this->authorizeAccess();
        $this->dispatch('mail-open-draft', id: $id);
    }

    public function loadMore(): void
    {
        $this->authorizeAccess();
        $this->pages = min(self::MAX_PAGES, $this->pages + 1);
    }

    #[On('mail-unread-changed')]
    public function refreshList(): void
    {
        $this->authorizeAccess();
    }

    public function render(): View
    {
        $user = $this->authorizeAccess();
        $query = app(MailboxQuery::class);
        $data = ['rows' => [], 'drafts' => [], 'hasMore' => false, 'error' => false, 'capped' => false, 'own' => app(MailComposer::class)->ownAddresses()];

        try {
            if ($this->folder === MailboxFilter::DRAFTS) {
                $data['drafts'] = $query->drafts($user)->all();
            } else {
                $filter = (new MailboxFilter($this->folder))
                    ->withSearch($this->search === '' ? null : $this->search)
                    ->withLabel($this->label === '' ? null : $this->label)
                    ->onlyUnread($this->unread === '1')
                    ->onlyWithAttachments($this->attachments === '1')
                    ->withPriority($this->priority === '' ? null : $this->priority);
                $cursor = null;
                for ($page = 0; $page < max(1, min(self::MAX_PAGES, $this->pages)); $page++) {
                    $result = $query->threads($user, $filter, $cursor, self::PER_PAGE);
                    array_push($data['rows'], ...$result->items());
                    $cursor = $result->nextCursor()?->encode();
                    if ($cursor === null) {
                        break;
                    }
                }
                $data['hasMore'] = $cursor !== null && $this->pages < self::MAX_PAGES;
                $data['capped'] = $cursor !== null && $this->pages >= self::MAX_PAGES;
            }
        } catch (MailActionRefused $refused) {
            throw $refused;
        } catch (Throwable $exception) {
            report($exception);
            $data['error'] = true;
        }

        return view('livewire.mail.mailbox-list', $data + [
            'folderLabel' => $this->folderLabel(),
            'canSend' => app(MailAccess::class)->canSend($user),
            'draftsMode' => $this->folder === MailboxFilter::DRAFTS,
            'filtered' => $this->search !== '' || $this->unread === '1' || $this->attachments === '1' || $this->label !== '' || $this->priority !== '',
        ]);
    }

    private function folderLabel(): string
    {
        return match ($this->folder) {
            MailboxFilter::ASSIGNED => 'Assigned to me',
            MailboxFilter::STARRED => 'Starred',
            MailboxFilter::DRAFTS => 'Drafts',
            MailboxFilter::SENT => 'Sent',
            MailboxFilter::AUTOMATED => 'Automated',
            MailboxFilter::ARCHIVE => 'Archive',
            MailboxFilter::TRASH => 'Trash',
            default => 'Inbox',
        };
    }

    private function authorizeAccess(): User
    {
        $user = auth()->user();
        if (! $user instanceof User || ! app(MailAccess::class)->canView($user)) {
            throw MailActionRefused::forbidden();
        }

        return $user;
    }
}
