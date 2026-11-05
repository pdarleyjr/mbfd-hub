<?php

declare(strict_types=1);

namespace App\Services\Activity\Sources;

use App\Filament\Employee\Pages\MyMessages;
use App\Models\Employee;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Models\User;
use App\Services\Activity\ActivitySignal;
use App\Services\Activity\LatestPerGroup;
use App\Services\Activity\MemberActivityItem;
use App\Services\Activity\MemberActivitySource;
use App\Services\Activity\MemberActivityText;
use App\Services\Activity\MemberActivityUpdate;
use App\Services\Security\EmployeeLogisticsMessagingAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The member's private conversations with Support Services. Ownership is the account (`member_user_id`), the same
 * rule My Messages uses. A thread counts as unread only for a reply a person wrote after the member last opened
 * it there; the assistant's receipts are shown but never make it unread.
 */
final class LogisticsMessageActivitySource implements MemberActivitySource
{
    public function __construct(private readonly EmployeeLogisticsMessagingAccess $access) {}

    public function items(User $user, ?Employee $employee, CarbonImmutable $since, int $limit): array
    {
        if (! $this->access->canUseMemberInbox($user)) {
            return [];
        }

        $threads = EmployeeLogisticsThread::query()
            ->where('member_user_id', $user->getKey())
            ->where(fn (Builder $recent) => $recent
                ->where('created_at', '>=', $since)
                ->orWhere('last_message_at', '>=', $since)
                ->orWhere('last_message_side', EmployeeLogisticsMessage::SIDE_MEMBER))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
        if ($threads->isEmpty()) {
            return [];
        }

        $signals = $this->signals($user, $threads->keyBy('id'));

        return $threads->map(function (EmployeeLogisticsThread $thread) use ($signals): MemberActivityItem {
            $id = (int) $thread->getKey();
            $signal = $signals[$id] ?? ActivitySignal::none();
            $at = MemberActivityText::moment($thread->last_message_at ?? $thread->created_at);

            return new MemberActivityItem(
                key: 'message:'.$id,
                category: 'Message',
                subject: MemberActivityText::DEPARTMENT,
                description: $thread->title(),
                statusLabel: $thread->memberStatus(),
                // Open while the team still owes the member a reply.
                isOpen: $thread->last_message_side === EmployeeLogisticsMessage::SIDE_MEMBER,
                needsResponse: false,
                latestUpdate: $signal->latest,
                at: $at,
                url: MyMessages::getUrl(['thread' => $id], panel: 'employee'),
                unreadAt: $signal->humanAt,
            );
        })->values()->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, EmployeeLogisticsThread>  $threads  keyed by thread id
     * @return array<int, ActivitySignal>
     */
    private function signals(User $user, $threads): array
    {
        $ids = array_map('intval', $threads->keys()->all());
        $shown = EmployeeLogisticsMessage::query()
            ->whereIn('thread_id', $ids)
            ->whereIn('author_side', [EmployeeLogisticsMessage::SIDE_LOGISTICS, EmployeeLogisticsMessage::SIDE_ASSISTANT]);
        $people = (clone $shown)->where('author_side', EmployeeLogisticsMessage::SIDE_LOGISTICS)
            ->where(fn (Builder $author) => $author->whereNull('author_user_id')->orWhere('author_user_id', '<>', $user->getKey()));

        $signals = [];
        foreach (LatestPerGroup::newest($shown, 'thread_id')->get(['id', 'thread_id', 'author_user_id', 'author_side', 'body', 'created_at']) as $message) {
            $assistant = $message->isFromAssistant();
            $own = ! $assistant && $message->author_user_id !== null && (int) $message->author_user_id === (int) $user->getKey();
            $signals[(int) $message->thread_id] = new ActivitySignal(new MemberActivityUpdate(
                MemberActivityText::excerpt((string) $message->body),
                $assistant ? MemberActivityText::assistantLabel() : ($own ? 'You' : MemberActivityText::DEPARTMENT),
                MemberActivityText::moment($message->created_at),
                $assistant,
            ));
        }
        foreach (LatestPerGroup::newest($people, 'thread_id')->get(['id', 'thread_id', 'created_at']) as $message) {
            $thread = (int) $message->thread_id;
            // Opening the conversation in My Messages marks it read by message id; honor that here too.
            if ((int) $message->id <= (int) $threads->get($thread)?->member_read_message_id) {
                continue;
            }
            $signals[$thread] = ($signals[$thread] ?? ActivitySignal::none())
                ->merge(new ActivitySignal(null, MemberActivityText::moment($message->created_at)));
        }

        return $signals;
    }
}
