<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Models\User;
use App\Services\EmployeeMessaging\EmployeeLogisticsMessagingService;
use App\Services\Exports\ExportDataset;
use App\Services\Security\EmployeeLogisticsMessagingAccess;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Logistics Dashboard card for Employee <-> Logistics messaging (docs/employee-logistics-messaging.md).
 *
 * Shows the latest conversations from one list query (no message bodies for collapsed rows).
 * Expanding a row shows its last messages and, for responders, the reply box. Only expanding a
 * row, or sending a reply, moves the Logistics read marker (to the newest message displayed);
 * the collapsed preview and the 30 second poll never do.
 */
final class EmployeeMessagesWidget extends Widget
{
    private const PREVIEW_ROWS = 5;

    private const EXPANDED_MESSAGES = 20;

    protected static string $view = 'filament.widgets.employee-messages-widget';

    protected static ?int $sort = 100;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** Captured when the card is first mounted; later Livewire requests do not carry ?display=1. */
    public bool $displayMode = false;

    public ?int $expandedThreadId = null;

    /** Set by the actions that open or answer a conversation; consumed by the next render only. */
    protected bool $markDisplayedRead = false;

    public static function canView(): bool
    {
        if (request()->boolean('display')) {
            return false;
        }
        $user = auth()->user();

        return $user instanceof User && app(EmployeeLogisticsMessagingAccess::class)->canViewLogisticsInbox($user);
    }

    public function mount(): void
    {
        $this->displayMode = request()->boolean('display');
        if (! $this->displayMode) {
            $this->authorizedActor();
        }
    }

    /** Runs on every subsequent Livewire request. */
    public function hydrate(): void
    {
        $this->authorizedActor();
    }

    public function toggleThread(int $threadId): void
    {
        $this->authorizedActor();
        if ($this->expandedThreadId === $threadId || $threadId < 1) {
            $this->expandedThreadId = null;

            return;
        }
        $this->expandedThreadId = $threadId;
        $this->markDisplayedRead = true;
    }

    /** Poll target: re-authorization only; the render never moves a marker for a poll. */
    public function poll(): void
    {
        $this->authorizedActor();
    }

    /**
     * Composer target for the expanded conversation.
     *
     * @return array{ok: bool, message?: string}
     */
    public function sendReply(string $body, string $requestId, ?int $threadId = null): array
    {
        $actor = $this->authorizedActor();
        if ($this->expandedThreadId === null) {
            return ['ok' => false, 'message' => 'This conversation is no longer available.'];
        }
        if ($threadId !== null && $threadId !== $this->expandedThreadId) {
            // The card switched conversations while this reply was on its way: never misdeliver it.
            return ['ok' => false, 'message' => 'You switched conversations while sending. Your message was not sent and is still here.'];
        }

        try {
            app(EmployeeLogisticsMessagingService::class)->replyAsLogistics($actor, $this->expandedThreadId, $body, $requestId);
        } catch (ValidationException $exception) {
            $first = collect($exception->errors())->flatten()->first();

            return ['ok' => false, 'message' => is_string($first) && $first !== '' ? $first : 'Your message could not be sent.'];
        } catch (AuthorizationException) {
            return ['ok' => false, 'message' => 'You no longer have permission to reply.'];
        } catch (ModelNotFoundException) {
            return ['ok' => false, 'message' => 'This conversation is no longer available.'];
        }

        // The render that follows shows the reply and moves the marker to the newest message displayed.
        $this->markDisplayedRead = true;

        return ['ok' => true];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        if ($this->displayMode) {
            return ['visible' => false];
        }

        $actor = $this->authorizedActor();
        $service = app(EmployeeLogisticsMessagingService::class);

        // The expanded conversation first: displaying it may move the read marker, and the list
        // and the unread count below must reflect that.
        $expanded = $this->expandedConversation($actor, $service);
        $threads = $service->logisticsThreads($actor, EmployeeLogisticsMessagingService::FILTER_ALL, null, self::PREVIEW_ROWS)['threads'];
        $unread = $service->logisticsUnreadCount($actor);

        // A newer conversation can push the open one out of the top rows; keep it on screen so a
        // reply being typed is never torn down by a poll.
        if ($expanded['thread'] !== null && ! $threads->contains('id', $expanded['thread']->id)) {
            $threads = $threads->push($expanded['thread']);
        }

        return [
            'visible' => true,
            'threads' => $threads,
            'unread' => $unread,
            'expandedId' => $expanded['thread']?->id,
            'messages' => $expanded['messages'],
            'hasEarlier' => $expanded['hasEarlier'],
            'canReply' => app(EmployeeLogisticsMessagingAccess::class)->canReplyAsLogistics($actor),
        ];
    }

    /**
     * @return array{thread: ?EmployeeLogisticsThread, messages: Collection<int, \App\Models\EmployeeMessaging\EmployeeLogisticsMessage>, hasEarlier: bool}
     */
    private function expandedConversation(User $actor, EmployeeLogisticsMessagingService $service): array
    {
        $mark = $this->markDisplayedRead;
        $this->markDisplayedRead = false;
        $empty = ['thread' => null, 'messages' => collect(), 'hasEarlier' => false];
        if ($this->expandedThreadId === null) {
            return $empty;
        }

        try {
            $thread = $service->logisticsThread($actor, $this->expandedThreadId);
        } catch (ModelNotFoundException) {
            $this->expandedThreadId = null;

            return $empty;
        }

        $window = $service->messagesFor($thread, self::EXPANDED_MESSAGES);
        $newest = $window['messages']->last();
        if ($mark && $newest !== null && (int) $newest->id > (int) $thread->logistics_read_message_id) {
            $service->markReadByLogistics($actor, (int) $thread->getKey(), (int) $newest->id);
        }

        return ['thread' => $thread, 'messages' => $window['messages'], 'hasEarlier' => $window['hasEarlier']];
    }

    /**
     * Dashboard export coverage contract (tests/Feature/Exports/PanelExportCoverageTest): this card
     * deliberately offers no export. Conversations are private and person-specific; they stay in
     * the Employee Messages inbox and are never written to a PDF or spreadsheet from the dashboard.
     */
    public function canExportData(User $actor): bool
    {
        return false;
    }

    protected function exportDataset(User $actor): ExportDataset
    {
        abort(403, 'Employee messages are private and cannot be exported.');
    }

    private function authorizedActor(): User
    {
        $user = auth()->user();
        abort_unless(
            $user instanceof User && app(EmployeeLogisticsMessagingAccess::class)->canViewLogisticsInbox($user),
            403,
        );

        return $user;
    }
}
