<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\Mail\MailDraft;
use App\Models\Mail\MailLabel;
use App\Models\User;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailActionRefused;
use App\Services\Mail\MailboxFilter;
use App\Services\Mail\MailboxQuery;
use App\Services\Mail\MailHealthReport;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Throwable;

/**
 * The Email Center (docs/email-center-architecture.md): one page over the mail the Hub already receives and
 * sends. This class owns only what the address bar must remember: the folder, the open conversation, whether a
 * message is being written, the draft being written and the search. Reading, acting and composing live in the
 * child Livewire components, which re-check access themselves on every request. Nothing here is trusted from the
 * browser: every value is re-validated on mount and on every update.
 */
final class EmailCenter extends Page
{
    public const HEALTH = 'health';

    private const BADGE_REQUEST_KEY = 'email_center.unread';

    private const COUNTS_REQUEST_KEY = 'email_center.counts';

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'Email';

    protected static ?string $title = 'Email';

    protected static ?string $slug = 'email';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.admin.pages.email-center';

    protected ?string $maxContentWidth = MaxWidth::Full->value;

    #[Url(as: 'folder', history: true)]
    public string $folder = MailboxFilter::INBOX;

    /** The open conversation. int|string so a hand-edited address bar cannot raise a type error. */
    #[Url(as: 'thread', history: true)]
    public int|string|null $thread = null;

    /** new, reply, reply_all or forward while a message is being written. */
    #[Url(as: 'compose', history: true)]
    public ?string $compose = null;

    /** The draft being written, so a refresh finds it again. */
    #[Url(as: 'draft', history: true)]
    public int|string|null $draft = null;

    /** The message a reply or forward answers, as "inbound:12" or "outbound:7"; empty means the newest. */
    #[Url(as: 'msg', history: true)]
    public ?string $message = null;

    #[Url(as: 'q')]
    public string $q = '';

    #[Url(as: 'unread')]
    public string $unread = '';

    #[Url(as: 'attachments')]
    public string $attachments = '';

    #[Url(as: 'label')]
    public string $label = '';

    #[Url(as: 'priority')]
    public string $priority = '';

    /** Raised each time a message is started on purpose, so the composer is rebuilt for it and for nothing else. */
    public int $composeRun = 0;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(MailAccess::class)->canView($user);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (! $user instanceof User || ! self::canAccess()) {
            return null;
        }
        $unread = self::unreadThisRequest($user);

        return $unread > 0 ? (string) $unread : null;
    }

    /** The sidebar and the header envelope draw the same number, and the sidebar draws more than once: one count per HTTP request. */
    public static function unreadThisRequest(User $user): int
    {
        $attributes = request()->attributes;
        if (! $attributes->has(self::BADGE_REQUEST_KEY)) {
            try {
                $attributes->set(self::BADGE_REQUEST_KEY, app(MailboxQuery::class)->unreadCount($user));
            } catch (Throwable $exception) {
                report($exception);
                $attributes->set(self::BADGE_REQUEST_KEY, 0);
            }
        }

        return (int) $attributes->get(self::BADGE_REQUEST_KEY);
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Unread conversations';
    }

    public function getHeading(): string
    {
        return 'Email';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->normalize();
    }

    /** Runs on every later Livewire request, so property updates and actions are re-authorized too. */
    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    public function updated(): void
    {
        $this->authorizeAccess();
        $this->normalize();
    }

    // ---------------------------------------------------------------------------------------------
    // Navigation
    // ---------------------------------------------------------------------------------------------

    public function openFolder(string $folder): void
    {
        $this->authorizeAccess();
        $this->folder = $folder;
        $this->thread = null;
        $this->compose = null;
        $this->draft = null;
        $this->message = null;
        $this->normalize();
    }

    public function openThread(int $id): void
    {
        $this->authorizeAccess();
        $this->thread = $id > 0 ? $id : null;
        $this->compose = null;
        $this->draft = null;
        $this->message = null;
    }

    public function closeThread(): void
    {
        $this->authorizeAccess();
        $this->thread = null;
        $this->compose = null;
        $this->draft = null;
        $this->message = null;
    }

    public function clearFilters(): void
    {
        $this->authorizeAccess();
        $this->q = '';
        $this->unread = '';
        $this->attachments = '';
        $this->label = '';
        $this->priority = '';
    }

    public function toggleFilter(string $name): void
    {
        $this->authorizeAccess();
        match ($name) {
            'unread' => $this->unread = $this->unread === '1' ? '' : '1',
            'attachments' => $this->attachments = $this->attachments === '1' ? '' : '1',
            'priority' => $this->priority = $this->priority === 'high' ? '' : 'high',
            default => null,
        };
    }

    public function startNewMessage(): void
    {
        $this->authorizeAccess();
        if (! $this->canSend()) {
            return;
        }
        $this->openCompose(MailDraft::MODE_NEW, null, null, null);
    }

    // ---------------------------------------------------------------------------------------------
    // Events from the child components
    // ---------------------------------------------------------------------------------------------

    #[On('mail-open-thread')]
    public function onOpenThread(int $id): void
    {
        $this->openThread($id);
    }

    /** @param  string|null  $source  "inbound:12" / "outbound:7" or null for the newest message */
    #[On('mail-compose')]
    public function onCompose(string $mode, ?int $thread = null, ?string $source = null): void
    {
        $this->authorizeAccess();
        if (! $this->canSend() || ! in_array($mode, MailDraft::MODES, true)) {
            return;
        }
        $this->openCompose($mode, $thread, $source, null);
    }

    #[On('mail-open-draft')]
    public function onOpenDraft(int $id): void
    {
        $user = $this->authorizeAccess();
        if (! $this->canSend()) {
            return;
        }
        $draft = MailDraft::query()->whereKey($id)->where('user_id', $user->getKey())->first();
        if ($draft === null || ! in_array($draft->state, [MailDraft::STATE_OPEN, MailDraft::STATE_UNCONFIRMED], true)) {
            return;
        }
        $source = $draft->source_kind !== null && $draft->source_id !== null ? "{$draft->source_kind}:{$draft->source_id}" : null;
        $this->openCompose((string) $draft->mode, $draft->thread_id === null ? null : (int) $draft->thread_id, $source, (int) $draft->getKey());
    }

    /** The composer made or found a draft: remember it in the address bar without rebuilding the composer. */
    #[On('mail-draft-opened')]
    public function onDraftOpened(int $id): void
    {
        $user = $this->authorizeAccess();
        if (MailDraft::query()->whereKey($id)->where('user_id', $user->getKey())->exists()) {
            $this->draft = $id;
        }
    }

    #[On('mail-compose-closed')]
    public function onComposeClosed(?int $thread = null, bool $showSent = false): void
    {
        $this->authorizeAccess();
        $this->compose = null;
        $this->draft = null;
        $this->message = null;
        if ($showSent && $thread !== null) {
            $this->folder = MailboxFilter::SENT;
            $this->thread = $thread;
        } elseif ($thread === null) {
            $this->thread = null;
        }
    }

    /** Anything that changes a folder count re-renders the folder list. */
    #[On('mail-unread-changed')]
    public function onCountsChanged(): void
    {
        $this->authorizeAccess();
    }

    // ---------------------------------------------------------------------------------------------
    // View data
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = $this->authorizeAccess();
        $this->normalize();
        $canSend = $this->canSend();
        $canManage = app(MailAccess::class)->canManage($user);
        $health = null;
        $healthError = false;
        if ($this->folder === self::HEALTH) {
            try {
                $health = app(MailHealthReport::class)->summary($user);
            } catch (Throwable) {
                $healthError = true;
            }
        }

        return [
            'counts' => $this->counts($user),
            'folderItems' => $this->folderItems($canManage),
            'canSend' => $canSend,
            'canManage' => $canManage,
            'composing' => $this->compose !== null && $canSend,
            'threadId' => $this->threadId(),
            'draftId' => $this->draftId(),
            'sourceKind' => $this->sourceParts()[0] ?? null,
            'sourceId' => $this->sourceParts()[1] ?? null,
            'filtersActive' => $this->q !== '' || $this->unread === '1' || $this->attachments === '1' || $this->label !== '' || $this->priority !== '',
            'labels' => MailLabel::query()->orderBy('name')->limit(100)->pluck('name')->all(),
            'health' => $health,
            'healthError' => $healthError,
            'departmentUpdatesUrl' => self::departmentUpdatesUrl(),
        ];
    }

    /** @return array<string, int> */
    private function counts(User $user): array
    {
        $attributes = request()->attributes;
        if (! $attributes->has(self::COUNTS_REQUEST_KEY)) {
            try {
                $attributes->set(self::COUNTS_REQUEST_KEY, app(MailboxQuery::class)->counts($user));
            } catch (Throwable) {
                $attributes->set(self::COUNTS_REQUEST_KEY, []);
            }
        }

        return (array) $attributes->get(self::COUNTS_REQUEST_KEY);
    }

    /** @return list<array{key: string, label: string, icon: string, count: bool}> */
    private function folderItems(bool $canManage): array
    {
        $items = [
            ['key' => MailboxFilter::INBOX, 'label' => 'Inbox', 'icon' => 'heroicon-o-inbox', 'count' => true],
            ['key' => MailboxFilter::ASSIGNED, 'label' => 'Assigned to me', 'icon' => 'heroicon-o-user-circle', 'count' => true],
            ['key' => MailboxFilter::STARRED, 'label' => 'Starred', 'icon' => 'heroicon-o-star', 'count' => true],
            ['key' => MailboxFilter::DRAFTS, 'label' => 'Drafts', 'icon' => 'heroicon-o-pencil-square', 'count' => true],
            ['key' => MailboxFilter::SENT, 'label' => 'Sent', 'icon' => 'heroicon-o-paper-airplane', 'count' => false],
            ['key' => MailboxFilter::AUTOMATED, 'label' => 'Automated', 'icon' => 'heroicon-o-cog-6-tooth', 'count' => false],
            ['key' => MailboxFilter::ARCHIVE, 'label' => 'Archive', 'icon' => 'heroicon-o-archive-box', 'count' => false],
        ];
        if ($canManage) {
            $items[] = ['key' => MailboxFilter::TRASH, 'label' => 'Trash', 'icon' => 'heroicon-o-trash', 'count' => false];
        }
        $items[] = ['key' => self::HEALTH, 'label' => 'Health', 'icon' => 'heroicon-o-heart', 'count' => false];

        return $items;
    }

    /** Where department-wide messages belong. Null when this member cannot open Department Updates. */
    public static function departmentUpdatesUrl(): ?string
    {
        try {
            $resource = \App\Filament\Resources\DepartmentUpdateResource::class;

            return $resource::canViewAny() ? $resource::getUrl('index') : null;
        } catch (Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Validation of what the address bar says
    // ---------------------------------------------------------------------------------------------

    private function openCompose(string $mode, ?int $thread, ?string $source, ?int $draft): void
    {
        $this->compose = $mode;
        $this->thread = $thread !== null && $thread > 0 ? $thread : ($mode === MailDraft::MODE_NEW ? null : $this->threadId());
        $this->message = $source;
        $this->draft = $draft;
        $this->composeRun++;
        $this->normalize();
    }

    private function normalize(): void
    {
        $canManage = app(MailAccess::class)->canManage(auth()->user());
        $allowed = [...MailboxFilter::FOLDERS, self::HEALTH];
        if (! in_array($this->folder, $allowed, true) || ($this->folder === MailboxFilter::TRASH && ! $canManage)) {
            $this->folder = MailboxFilter::INBOX;
        }
        $this->thread = $this->threadId();
        $this->draft = $this->draftId();
        if ($this->compose !== null && (! $this->canSend() || ! in_array($this->compose, MailDraft::MODES, true))) {
            $this->compose = null;
            $this->draft = null;
        }
        if ($this->compose === null) {
            $this->message = null;
        }
        if ($this->message !== null && preg_match('/^(inbound|outbound):[1-9][0-9]{0,18}$/', $this->message) !== 1) {
            $this->message = null;
        }
        $this->q = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $this->q)), 0, MailboxFilter::MAX_SEARCH_LENGTH);
        $this->unread = $this->unread === '1' ? '1' : '';
        $this->attachments = $this->attachments === '1' ? '1' : '';
        $this->priority = $this->priority === 'high' ? 'high' : '';
        $this->label = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F<>]+/u', '', $this->label)), 0, 40);
    }

    private function threadId(): ?int
    {
        $id = filter_var($this->thread, FILTER_VALIDATE_INT);

        return is_int($id) && $id > 0 ? $id : null;
    }

    private function draftId(): ?int
    {
        $id = filter_var($this->draft, FILTER_VALIDATE_INT);

        return is_int($id) && $id > 0 ? $id : null;
    }

    /** @return array{0?: string, 1?: int} */
    private function sourceParts(): array
    {
        if ($this->message === null || preg_match('/^(inbound|outbound):([1-9][0-9]{0,18})$/', $this->message, $found) !== 1) {
            return [];
        }

        return [$found[1], (int) $found[2]];
    }

    private function canSend(): bool
    {
        return app(MailAccess::class)->canSend(auth()->user());
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
