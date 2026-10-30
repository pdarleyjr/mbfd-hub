<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Models\User;
use App\Services\EmployeeMessaging\EmployeeLogisticsMessagingService;
use App\Services\Security\EmployeeLogisticsMessagingAccess;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * My Messages: a member's private conversations with Support Services (formerly Logistics)
 * (docs/employee-logistics-messaging.md). All reads and writes go through
 * EmployeeLogisticsMessagingService with the signed-in member, re-authorized on every request.
 */
class MyMessages extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = 'My work';

    protected static string $view = 'filament.employee.pages.my-messages';

    protected static ?string $title = 'My Messages';

    protected static ?string $navigationLabel = 'My Messages';

    protected static ?string $slug = 'messages';

    protected static ?int $navigationSort = 5;

    /** Untrusted URL input (?thread=): read it through selectedThreadId(), never directly. */
    #[Url(as: 'thread')]
    public int|string|null $threadId = null;

    #[Url(as: 'compose')]
    public bool $composing = false;

    public int $limit = EmployeeLogisticsMessagingService::THREAD_PAGE_SIZE;

    /** Set by the actions that open or answer a conversation; consumed by the next render only. */
    protected bool $markDisplayedRead = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(EmployeeLogisticsMessagingAccess::class)->canUseMemberInbox($user);
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (! $user instanceof User || ! static::canAccess()) {
            return null;
        }
        $unread = app(EmployeeLogisticsMessagingService::class)->memberUnreadCount($user);

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'New replies from Support Services';
    }

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->threadId = $this->selectedThreadId();
        if ($this->threadId !== null) {
            $this->composing = false;
            $this->markDisplayedRead = true;
        }
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    public function startCompose(): void
    {
        $this->composing = true;
        $this->threadId = null;
    }

    public function openThread(int $threadId): void
    {
        $this->threadId = $threadId > 0 ? $threadId : null;
        $this->composing = false;
        $this->markDisplayedRead = $this->threadId !== null;
    }

    public function backToList(): void
    {
        $this->threadId = null;
        $this->composing = false;
    }

    public function showMore(): void
    {
        $this->limit = min($this->limit + EmployeeLogisticsMessagingService::THREAD_PAGE_SIZE, 500);
    }

    /** Polls only re-render (never mark anything read); the composer is wire:ignore and keeps whatever the member typed. */
    public function poll(): void {}

    /** @return array{ok: bool, message?: string} */
    public function startThread(string $body, string $subject, string $requestId, ?string $reason = null): array
    {
        return $this->attempt(function (User $member, EmployeeLogisticsMessagingService $messages) use ($body, $subject, $requestId, $reason): void {
            $message = $messages->startThread($member, $body, $subject, $requestId, $reason);
            $this->threadId = $message->thread_id;
            $this->composing = false;
            $this->markDisplayedRead = true;
        });
    }

    /** @return array{ok: bool, message?: string} */
    public function sendReply(string $body, string $requestId, ?int $threadId = null): array
    {
        $selected = $this->selectedThreadId();
        if ($threadId !== null && $threadId !== $selected) {
            // The page switched conversations while this reply was on its way: never misdeliver it.
            return ['ok' => false, 'message' => 'You switched conversations while sending. Your message was not sent and is still here.'];
        }

        return $this->attempt(function (User $member, EmployeeLogisticsMessagingService $messages) use ($body, $requestId, $selected): void {
            $messages->replyAsMember($member, $selected ?? throw new ModelNotFoundException, $body, $requestId);
            $this->markDisplayedRead = true;
        });
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $member = $this->member();
        $messages = app(EmployeeLogisticsMessagingService::class);
        $list = $messages->memberThreads($member, $this->limit);

        $thread = null;
        $conversation = null;
        $missing = false;
        $threadId = $this->selectedThreadId();
        if ($threadId !== null) {
            try {
                $thread = $messages->memberThread($member, $threadId);
                $conversation = $messages->messagesFor($thread);
                $newest = $conversation['messages']->last();
                $mark = $this->markDisplayedRead;
                $this->markDisplayedRead = false;
                if ($mark && $newest !== null && $thread->isUnreadForMember()) {
                    // Opened (or answered) now, so read now; a list refresh, poll or badge never does this.
                    $messages->markReadByMember($member, $thread->id, (int) $newest->id);
                }
            } catch (ModelNotFoundException) {
                $missing = true;
            }
        }

        return [
            'threads' => $list['threads'],
            'hasMore' => $list['hasMore'],
            'thread' => $thread,
            'conversation' => $conversation,
            'missingThread' => $missing,
            'unreadIds' => $list['threads']->filter(fn (EmployeeLogisticsThread $t): bool => $t->isUnreadForMember() && $t->id !== $thread?->id)
                ->pluck('id')->all(),
        ];
    }

    /**
     * @param  callable(User, EmployeeLogisticsMessagingService): void  $action
     * @return array{ok: bool, message?: string}
     */
    private function attempt(callable $action): array
    {
        try {
            $this->authorizeAccess();
            $action($this->member(), app(EmployeeLogisticsMessagingService::class));

            return ['ok' => true];
        } catch (ValidationException $exception) {
            return ['ok' => false, 'message' => (string) collect($exception->errors())->flatten()->first()];
        } catch (ModelNotFoundException) {
            return ['ok' => false, 'message' => 'This conversation is no longer available. Your message is still here.'];
        } catch (AuthorizationException) {
            return ['ok' => false, 'message' => 'Your account can no longer send messages here. Sign in again or contact Support Services.'];
        }
    }

    private function selectedThreadId(): ?int
    {
        $id = filter_var($this->threadId, FILTER_VALIDATE_INT);

        return is_int($id) && $id > 0 ? $id : null;
    }

    private function member(): User
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }
}
