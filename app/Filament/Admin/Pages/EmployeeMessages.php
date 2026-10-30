<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Models\User;
use App\Services\EmployeeMessaging\EmployeeLogisticsMessagingService;
use App\Services\EmployeeMessaging\EmployeeMessageReason;
use App\Services\Security\EmployeeLogisticsMessagingAccess;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * Support Services inbox (formerly the Logistics inbox) for member messaging (docs/employee-logistics-messaging.md).
 *
 * A thin Livewire view over EmployeeLogisticsMessagingService: this class never queries threads or
 * messages itself, never accepts an owner, author or side from the browser, and re-checks
 * EmployeeLogisticsMessagingAccess on every Livewire request (hydrate) and every action.
 *
 * Read markers move only for a conversation that is actually rendered to the signed-in viewer:
 * opening a thread, loading the page with ?thread=, a reply, or a poll while that thread is on
 * screen. A poll that shows only the list never moves a marker.
 */
final class EmployeeMessages extends Page
{
    private const MAX_SEARCH_LENGTH = 100;

    private const MAX_WINDOW = 500;

    private const BADGE_REQUEST_KEY = 'employee_messages.logistics_unread';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'Support Services Messages';

    protected static ?string $title = 'Support Services Messages';

    protected static ?string $slug = 'employee-messages';

    protected static ?int $navigationSort = 35;

    protected static string $view = 'filament.admin.pages.employee-messages';

    protected ?string $maxContentWidth = MaxWidth::Full->value;

    /** The open conversation. Whatever the browser sends is re-validated and re-fetched through the service. */
    #[Url(as: 'thread')]
    public int|string|null $threadId = null;

    /** One of EmployeeLogisticsMessagingService::FILTER_*. */
    public string $filter = EmployeeLogisticsMessagingService::FILTER_ALL;

    public string $search = '';

    /** An EmployeeMessageReason key, or '' for every reason. */
    public string $reasonFilter = '';

    /** How many conversations the list window shows ("Show more" raises it by one page). */
    public int $limit = EmployeeLogisticsMessagingService::THREAD_PAGE_SIZE;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(EmployeeLogisticsMessagingAccess::class)->canViewLogisticsInbox($user);
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
        // Filament draws the navigation more than once per page; count once per HTTP request.
        $attributes = request()->attributes;
        if (! $attributes->has(self::BADGE_REQUEST_KEY)) {
            $attributes->set(self::BADGE_REQUEST_KEY, app(EmployeeLogisticsMessagingService::class)->logisticsUnreadCount($user));
        }
        $unread = (int) $attributes->get(self::BADGE_REQUEST_KEY);

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Unread Support Services messages';
    }

    public function getSubheading(): string
    {
        return 'Private messages from members to Support Services. Replies appear in the member\'s Employee Portal.';
    }

    /** Set by the actions that open or answer a conversation; consumed by the next render only. */
    protected bool $markDisplayedRead = false;

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->threadId = $this->selectedThreadId();
        $this->markDisplayedRead = $this->threadId !== null;
        $this->filter = $this->activeFilter();
        $this->limit = $this->windowLimit();
    }

    /** Runs on every subsequent Livewire request, so property updates and actions are re-authorized too. */
    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    public function openThread(int $threadId): void
    {
        $this->authorizeAccess();
        $this->threadId = $threadId > 0 ? $threadId : null;
        $this->markDisplayedRead = $this->threadId !== null;
    }

    public function closeThread(): void
    {
        $this->authorizeAccess();
        $this->threadId = null;
    }

    public function setFilter(string $filter): void
    {
        $this->authorizeAccess();
        $this->filter = $filter;
        $this->updatedFilter();
    }

    public function updatedFilter(): void
    {
        $this->authorizeAccess();
        $this->filter = $this->activeFilter();
        $this->limit = EmployeeLogisticsMessagingService::THREAD_PAGE_SIZE;
    }

    public function updatedReasonFilter(): void
    {
        $this->authorizeAccess();
        $this->reasonFilter = $this->activeReason() ?? '';
        $this->limit = EmployeeLogisticsMessagingService::THREAD_PAGE_SIZE;
    }

    public function updatedSearch(): void
    {
        $this->authorizeAccess();
        $this->search = mb_substr($this->search, 0, self::MAX_SEARCH_LENGTH);
        $this->limit = EmployeeLogisticsMessagingService::THREAD_PAGE_SIZE;
    }

    public function updatedLimit(): void
    {
        $this->authorizeAccess();
        $this->limit = $this->windowLimit();
    }

    public function showMore(): void
    {
        $this->authorizeAccess();
        $this->limit = min($this->windowLimit() + EmployeeLogisticsMessagingService::THREAD_PAGE_SIZE, self::MAX_WINDOW);
    }

    /** Poll target: re-authorization only. A poll never moves a read marker (nobody asked to read). */
    public function poll(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Composer target. $threadId is the conversation the reply box was drawn for: if the page has
     * switched to another conversation meanwhile, the reply is refused rather than misdelivered.
     * Owner, side and author are all decided by the service from the signed-in user.
     *
     * @return array{ok: bool, message?: string}
     */
    public function sendReply(string $body, string $requestId, ?int $threadId = null): array
    {
        $actor = $this->authorizeAccess();
        $selected = $this->selectedThreadId();
        if ($selected === null) {
            return ['ok' => false, 'message' => 'This conversation is no longer available.'];
        }
        if ($threadId !== null && $threadId !== $selected) {
            return ['ok' => false, 'message' => 'You switched conversations while sending. Your message was not sent and is still here.'];
        }
        $threadId = $selected;

        try {
            app(EmployeeLogisticsMessagingService::class)->replyAsLogistics($actor, $threadId, $body, $requestId);
        } catch (ValidationException $exception) {
            $first = collect($exception->errors())->flatten()->first();

            return ['ok' => false, 'message' => is_string($first) && $first !== '' ? $first : 'Your message could not be sent.'];
        } catch (AuthorizationException) {
            return ['ok' => false, 'message' => 'You no longer have permission to reply.'];
        } catch (ModelNotFoundException) {
            return ['ok' => false, 'message' => 'This conversation is no longer available.'];
        }

        // The render that follows shows the new message and everything before it, and moves the
        // Logistics read marker up to the newest message actually displayed.
        $this->markDisplayedRead = true;

        return ['ok' => true];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $actor = $this->authorizeAccess();
        $service = app(EmployeeLogisticsMessagingService::class);
        $filter = $this->activeFilter();
        $search = trim($this->search);

        // The open conversation first: displaying it moves the read marker, and the list and the
        // unread count below must reflect that.
        $conversation = $this->conversation($actor, $service);
        $list = $service->logisticsThreads($actor, $filter, $search === '' ? null : $search, $this->windowLimit(), $this->activeReason());

        return [
            'threads' => $list['threads'],
            'hasMore' => $list['hasMore'],
            'unreadTotal' => $service->logisticsUnreadCount($actor),
            'filter' => $filter,
            'filters' => [
                EmployeeLogisticsMessagingService::FILTER_ALL => 'All',
                EmployeeLogisticsMessagingService::FILTER_UNREAD => 'Unread',
                EmployeeLogisticsMessagingService::FILTER_AWAITING_REPLY => 'Awaiting reply',
            ],
            'filtered' => $filter !== EmployeeLogisticsMessagingService::FILTER_ALL || $search !== '' || $this->activeReason() !== null,
            'reasons' => EmployeeMessageReason::options(),
            'reasonFilter' => $this->activeReason() ?? '',
            'awaitingTotal' => $service->logisticsAwaitingCount($actor),
            'selectedId' => $this->selectedThreadId(),
            'thread' => $conversation['thread'],
            'messages' => $conversation['messages'],
            'hasEarlier' => $conversation['hasEarlier'],
            'threadMissing' => $conversation['missing'],
            'canReply' => app(EmployeeLogisticsMessagingAccess::class)->canReplyAsLogistics($actor),
        ];
    }

    /**
     * Loads the open thread and its newest messages. Only right after the member of staff opened
     * or answered it does this record them as read; polls, search and filters never do.
     *
     * @return array{thread: ?EmployeeLogisticsThread, messages: \Illuminate\Support\Collection<int, \App\Models\EmployeeMessaging\EmployeeLogisticsMessage>, hasEarlier: bool, missing: bool}
     */
    private function conversation(User $actor, EmployeeLogisticsMessagingService $service): array
    {
        $empty = ['thread' => null, 'messages' => collect(), 'hasEarlier' => false, 'missing' => false];
        $threadId = $this->selectedThreadId();
        if ($threadId === null) {
            return $empty;
        }

        try {
            $thread = $service->logisticsThread($actor, $threadId);
        } catch (ModelNotFoundException) {
            return [...$empty, 'missing' => true];
        }

        $mark = $this->markDisplayedRead;
        $this->markDisplayedRead = false;
        $window = $service->messagesFor($thread);
        $newest = $window['messages']->last();
        if ($mark && $newest !== null && (int) $newest->id > (int) $thread->logistics_read_message_id) {
            $service->markReadByLogistics($actor, $threadId, (int) $newest->id);
        }

        return ['thread' => $thread, 'messages' => $window['messages'], 'hasEarlier' => $window['hasEarlier'], 'missing' => false];
    }

    private function selectedThreadId(): ?int
    {
        $id = filter_var($this->threadId, FILTER_VALIDATE_INT);

        return is_int($id) && $id > 0 ? $id : null;
    }

    private function activeFilter(): string
    {
        return in_array($this->filter, [
            EmployeeLogisticsMessagingService::FILTER_ALL,
            EmployeeLogisticsMessagingService::FILTER_UNREAD,
            EmployeeLogisticsMessagingService::FILTER_AWAITING_REPLY,
        ], true) ? $this->filter : EmployeeLogisticsMessagingService::FILTER_ALL;
    }

    private function activeReason(): ?string
    {
        return EmployeeMessageReason::isValid($this->reasonFilter) ? $this->reasonFilter : null;
    }

    private function windowLimit(): int
    {
        return max(EmployeeLogisticsMessagingService::THREAD_PAGE_SIZE, min($this->limit, self::MAX_WINDOW));
    }

    private function authorizeAccess(): User
    {
        abort_unless(self::canAccess(), 403);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
