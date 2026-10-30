<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Station;

use App\Filament\Admin\Pages\EmployeeMessages;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Models\User;
use App\Services\EmployeeMessaging\EmployeeLogisticsMessagingService;
use App\Services\EmployeeMessaging\EmployeeMessageReason;
use App\Services\Security\EmployeeLogisticsMessagingAccess;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Support Services member conversations (the shared Logistics inbox; EmployeeMessagesWidget and
 * the Employee Messages page). Private and person-specific: never in display mode, and only for
 * accounts EmployeeLogisticsMessagingAccess lets into the inbox, exactly like the widget.
 *
 * Stage: the member wrote last → awaiting_response ("Awaiting reply"); Support Services wrote last
 * → resolved ("Replied"), shown for the operational day by last_message_at. Messages carry no
 * priority, so every row is severity "low" and "high" is always 0. No station tags: a thread is tied
 * to a member, not a station (member_station is only a snapshot of where the member works).
 *
 * Read markers: rows(), counts() and detail() never move the shared team marker. Only the explicit
 * "mark_read" action, or a reply (which, like the widget's sendReply, marks the conversation seen
 * up to the reply that was just sent), calls markReadByLogistics().
 *
 * @extends QueryBackedSource<EmployeeLogisticsThread>
 */
final class SupportMessageSource extends QueryBackedSource
{
    public const DETAIL_MESSAGES = 20;

    public function __construct(
        private readonly EmployeeLogisticsMessagingService $messaging,
        private readonly EmployeeLogisticsMessagingAccess $access,
    ) {}

    public function type(): string
    {
        return 'support_message';
    }

    public function views(): array
    {
        return [DashboardView::Overview];
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return ! $displayMode && $this->access->canViewLogisticsInbox($user);
    }

    protected function baseQuery(): Builder
    {
        return EmployeeLogisticsThread::query();
    }

    protected function whereOpen(Builder $query): void
    {
        $query->where('last_message_side', EmployeeLogisticsMessage::SIDE_MEMBER);
    }

    protected function whereStage(Builder $query, string $stage, OperationalDisplayWindow $window): void
    {
        match ($stage) {
            'awaiting_response' => $this->whereOpen($query),
            'resolved' => $query->where('last_message_side', EmployeeLogisticsMessage::SIDE_LOGISTICS)
                ->where(fn (Builder $q) => self::whereInWindow($q, 'last_message_at', $window)),
            default => self::nothing($query),
        };
    }

    protected function whereSeverity(Builder $query, string $severity): void
    {
        if ($severity !== 'low') {
            self::nothing($query);
        }
    }

    protected function tagConstraints(): array
    {
        return [];
    }

    protected function prepareRows(Builder $query): void
    {
        $query->orderByRaw('CASE WHEN last_message_side = ? THEN 0 ELSE 1 END', [EmployeeLogisticsMessage::SIDE_MEMBER])
            ->orderByDesc('last_message_at')->orderByDesc('id');
    }

    /** @param EmployeeLogisticsThread $record */
    protected function toIssue(Model $record, bool $displayMode): DashboardIssue
    {
        $awaiting = $record->last_message_side === EmployeeLogisticsMessage::SIDE_MEMBER;

        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: $record->title(),
            summary: $displayMode ? null : $record->last_message_preview,
            severity: 'low',
            status: (string) $record->last_message_side,
            statusLabel: $record->supportStatus(),
            stage: $awaiting ? 'awaiting_response' : 'resolved',
            tags: [],
            subject: null,
            imageKey: null,
            actorLabel: $displayMode ? null : ($record->member_name ?: null),
            categoryLabel: EmployeeMessageReason::label($record->reason),
            occurredAt: CarbonImmutable::instance($record->last_message_at ?? $record->created_at),
            version: self::versionOf($record),
            sourceUrl: EmployeeMessages::getUrl(['thread' => $record->getKey()], panel: 'admin'),
        );
    }

    public function detail(User $user, string $id): ?IssueDetail
    {
        if (! $this->visibleTo($user, false)) {
            return null;
        }
        try {
            $thread = $this->messaging->logisticsThread($user, (int) $id);
        } catch (ModelNotFoundException|AuthorizationException) {
            return null;
        }

        $window = $this->messaging->messagesFor($thread, self::DETAIL_MESSAGES);
        $timeline = $window['messages']->map(fn (EmployeeLogisticsMessage $message): array => [
            'at' => CarbonImmutable::instance($message->created_at),
            'actor' => $message->author_name,
            'text' => (string) $message->body,
        ])->values()->all();
        $readBy = $thread->logistics_read_by_user_id !== null ? User::query()->whereKey($thread->logistics_read_by_user_id)->value('name') : null;
        $facts = array_values(array_filter([
            ['label' => 'From', 'value' => (string) $thread->member_name],
            filled($thread->member_employee_number) ? ['label' => 'Employee #', 'value' => (string) $thread->member_employee_number] : null,
            filled($thread->member_rank) ? ['label' => 'Rank', 'value' => (string) $thread->member_rank] : null,
            filled($thread->member_station) ? ['label' => 'Station', 'value' => (string) $thread->member_station] : null,
            ['label' => 'Reason', 'value' => EmployeeMessageReason::label($thread->reason) ?? 'Not given'],
            ['label' => 'Status', 'value' => $thread->supportStatus()],
            ['label' => 'Messages', 'value' => (string) $thread->message_count.($window['hasEarlier'] ? ' (latest '.self::DETAIL_MESSAGES.' shown)' : '')],
            ['label' => 'Unread by Support Services', 'value' => $thread->isUnreadForLogistics() ? 'Yes' : 'No'],
            $readBy !== null ? ['label' => 'Last opened by', 'value' => $readBy] : null,
        ]));

        $actions = [];
        if ($this->access->canReplyAsLogistics($user)) {
            $actions[] = new IssueAction('reply', 'Reply', 'primary', [[
                'name' => 'message', 'label' => 'Reply', 'type' => 'textarea', 'required' => true,
                'max' => EmployeeLogisticsMessagingService::MAX_BODY_LENGTH,
            ]]);
        }
        if ($thread->isUnreadForLogistics()) {
            $actions[] = new IssueAction('mark_read', 'Mark as read', 'secondary');
        }
        $reason = $actions === [] ? 'You can read Support Services messages but not reply to them.' : null;

        return new IssueDetail($this->toIssue($thread, false), null, $facts, $timeline, $actions, $reason);
    }

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        if (! $this->visibleTo($user, false)) {
            return ActionResult::forbidden();
        }

        return match ($action) {
            'reply' => $this->reply($user, (int) $id, is_string($input['message'] ?? null) ? $input['message'] : '', $expectedVersion),
            'mark_read' => $this->markRead($user, (int) $id, $expectedVersion),
            default => ActionResult::invalid(['action' => 'This action is not available.']),
        };
    }

    /**
     * The widget's reply path: EmployeeLogisticsMessagingService::replyAsLogistics() (same access
     * check, validation, throttle, idempotency and notifications), then the marker moves to the
     * reply just sent, as the widget's next render does.
     */
    private function reply(User $user, int $threadId, string $body, string $expectedVersion): ActionResult
    {
        if (! $this->access->canReplyAsLogistics($user)) {
            return ActionResult::forbidden('You no longer have permission to reply.');
        }
        // One id per (admin, conversation, version seen, text): an identical resend is a replay.
        $requestId = 'dash-'.substr(hash('sha256', implode('|', [$user->getKey(), $threadId, $expectedVersion, $body])), 0, 40);

        try {
            if (EmployeeLogisticsMessage::query()->where('author_user_id', $user->getKey())->where('client_request_id', $requestId)->exists()) {
                $this->messaging->replyAsLogistics($user, $threadId, $body, $requestId);

                return ActionResult::done('Already sent.');
            }

            return DB::transaction(function () use ($user, $threadId, $body, $requestId, $expectedVersion): ActionResult {
                $thread = EmployeeLogisticsThread::query()->whereKey($threadId)->lockForUpdate()->first();
                if (! $thread instanceof EmployeeLogisticsThread) {
                    return ActionResult::gone('This conversation is no longer available.');
                }
                if (self::versionOf($thread) !== $expectedVersion) {
                    return ActionResult::conflict('A new message arrived in this conversation. It has been loaded; review it and send again. Your reply was not sent.');
                }
                $reply = $this->messaging->replyAsLogistics($user, $threadId, $body, $requestId);
                $this->messaging->markReadByLogistics($user, $threadId, (int) $reply->getKey());

                return ActionResult::done('Reply sent.');
            });
        } catch (ValidationException $exception) {
            $first = collect($exception->errors())->flatten()->first();

            return ActionResult::invalid(['message' => is_string($first) && $first !== '' ? $first : 'Your message could not be sent.']);
        } catch (AuthorizationException) {
            return ActionResult::forbidden('You no longer have permission to reply.');
        } catch (ModelNotFoundException) {
            return ActionResult::gone('This conversation is no longer available.');
        }
    }

    /** Explicit "Mark as read": the shared team marker, up to the newest message this admin saw. */
    private function markRead(User $user, int $threadId, string $expectedVersion): ActionResult
    {
        return DB::transaction(function () use ($user, $threadId, $expectedVersion): ActionResult {
            $thread = EmployeeLogisticsThread::query()->whereKey($threadId)->lockForUpdate()->first();
            if (! $thread instanceof EmployeeLogisticsThread) {
                return ActionResult::gone('This conversation is no longer available.');
            }
            if (self::versionOf($thread) !== $expectedVersion) {
                return ActionResult::conflict('A new message arrived in this conversation. Review it before marking it read.');
            }
            $newest = max((int) $thread->last_member_message_id, (int) $thread->last_logistics_message_id);
            try {
                $this->messaging->markReadByLogistics($user, $threadId, $newest);
            } catch (AuthorizationException) {
                return ActionResult::forbidden();
            }

            return ActionResult::done('Marked as read.');
        });
    }

    /** Changes with every new message, never with a read marker. */
    private static function versionOf(EmployeeLogisticsThread $thread): string
    {
        return 'm'.(int) $thread->last_member_message_id.'-l'.(int) $thread->last_logistics_message_id;
    }
}
