<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\InboundEmail;
use App\Models\Mail\MailDraft;
use App\Models\Mail\MailThread;
use App\Models\Mail\MailThreadMessage;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Communications\EmailConversation;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reads the mailbox for one member. Every method authorizes first: the page is only a client.
 *
 * Folder rules (conversation = kind "conversation"; automated mail never counts as unread):
 *   inbox      conversations with inbound mail, not archived, trashed, or snoozed
 *   assigned   conversations assigned to the member, not archived or trashed
 *   starred    threads the member starred, not trashed
 *   drafts     threads on which the member has an open draft (use drafts() for thread-less ones)
 *   sent       threads containing a message a person sent, not trashed
 *   automated  roster mail and system notifications, always read-only
 *   archive    archived conversations, not trashed
 *   trash      trashed conversations
 */
final class MailboxQuery
{
    public function __construct(
        private readonly MailAccess $access,
        private readonly MailText $text,
        private readonly AddressParser $addresses,
        private readonly MailAttachmentLocator $locator,
        private readonly EmailConversation $conversation,
    ) {}

    /** @return CursorPaginator<int, MailThread> each item carries is_unread and is_starred attributes for $user */
    public function threads(User $user, MailboxFilter $filter, ?string $cursor = null, int $perPage = 30): CursorPaginator
    {
        $this->authorize($user);
        $query = MailThread::query()->select('mail_threads.*')->addSelect([
            'user_read_through_at' => $this->stateColumn($user, 'read_through_at'),
            'user_starred_at' => $this->stateColumn($user, 'starred_at'),
            'user_marked_unread_at' => $this->stateColumn($user, 'marked_unread_at'),
        ])->with(['labels:id,name,color', 'assignee:id,name']);

        $this->applyFolder($query, $user, $filter->folder);
        if ($filter->unreadOnly) {
            $this->applyUnread($query, $user);
        }
        if ($filter->withAttachments) {
            $query->where('mail_threads.has_attachments', true);
        }
        if ($filter->priority !== null) {
            $query->where('mail_threads.priority', $filter->priority);
        }
        if ($filter->label !== null) {
            $query->whereExists(fn (QueryBuilder $labels) => $labels->selectRaw('1')->from('mail_thread_labels')
                ->join('mail_labels', 'mail_labels.id', '=', 'mail_thread_labels.label_id')
                ->whereColumn('mail_thread_labels.thread_id', 'mail_threads.id')
                ->whereRaw('lower(mail_labels.name) = ?', [mb_strtolower($filter->label)]));
        }
        if ($filter->search !== null) {
            $this->applySearch($query, $filter->search);
        }

        $page = $query->orderByDesc('mail_threads.last_message_at')->orderByDesc('mail_threads.id')
            ->cursorPaginate(max(1, min(100, $perPage)), ['mail_threads.*'], 'cursor', $cursor);

        return $page->through(function (MailThread $thread): MailThread {
            $thread->setAttribute('is_unread', $this->unread($thread));
            $thread->setAttribute('is_starred', $thread->getAttribute('user_starred_at') !== null);

            return $thread;
        });
    }

    public function thread(User $user, int $threadId): MailThreadDetail
    {
        $this->authorize($user);
        $thread = MailThread::query()->select('mail_threads.*')->addSelect([
            'user_read_through_at' => $this->stateColumn($user, 'read_through_at'),
            'user_starred_at' => $this->stateColumn($user, 'starred_at'),
            'user_marked_unread_at' => $this->stateColumn($user, 'marked_unread_at'),
        ])->with(['labels:id,name,color', 'assignee:id,name'])
            // Trash is for members who can restore from it; to everyone else a trashed conversation is gone.
            ->when(! $this->access->canManage($user), fn (Builder $query) => $query->whereNull('mail_threads.trashed_at'))
            ->findOrFail($threadId);

        $members = MailThreadMessage::query()->where('thread_id', $thread->getKey())->orderBy('message_at')->orderBy('id')->get();
        $inbounds = InboundEmail::query()->whereIn('id', $members->pluck('inbound_email_id')->filter())->get()->keyBy('id');
        $outbounds = OutboundEmail::query()->whereIn('id', $members->pluck('outbound_email_id')->filter())->get()->keyBy('id');

        $messages = [];
        foreach ($members as $member) {
            if ($member->inbound_email_id !== null && $inbounds->has($member->inbound_email_id)) {
                $email = $inbounds[$member->inbound_email_id];
                $messages[] = MailMessageView::fromInbound($email, $this->text, $this->addresses, $this->locator, $this->conversation->canRespond($email));
            } elseif ($member->outbound_email_id !== null && $outbounds->has($member->outbound_email_id)) {
                $email = $outbounds[$member->outbound_email_id];
                $messages[] = MailMessageView::fromOutbound($email, $user, $this->text, $this->addresses, $this->locator, $this->conversation->canRespond($email));
            }
        }

        $unread = $this->unread($thread);

        return new MailThreadDetail(
            thread: $thread,
            messages: $messages,
            notes: $thread->notes()->with('author:id,name')->get(),
            events: $thread->events()->with('actor:id,name')->get(),
            drafts: MailDraft::query()->where('thread_id', $thread->getKey())->where('user_id', $user->getKey())
                ->whereIn('state', [MailDraft::STATE_OPEN, MailDraft::STATE_UNCONFIRMED])->orderBy('id')->get(),
            isUnread: $unread,
            isStarred: $thread->getAttribute('user_starred_at') !== null,
            canAct: ! $thread->isAutomated(),
            canSend: ! $thread->isAutomated() && ! $thread->isTrashed() && $this->access->canSend($user),
            canManage: ! $thread->isAutomated() && $this->access->canManage($user),
        );
    }

    /**
     * One number per folder: inbox is the unread count, drafts is the member's own drafts, every other folder
     * is its total number of threads.
     *
     * @return array<string, int>
     */
    public function counts(User $user): array
    {
        $this->authorize($user);
        $counts = [];
        foreach (MailboxFilter::FOLDERS as $folder) {
            $counts[$folder] = match ($folder) {
                MailboxFilter::INBOX => $this->unreadCount($user),
                MailboxFilter::DRAFTS => $this->drafts($user)->count(),
                default => $this->folderQuery($user, $folder)->count(),
            };
        }

        return $counts;
    }

    public function unreadCount(User $user): int
    {
        $this->authorize($user);
        $query = MailThread::query();
        $this->applyFolder($query, $user, MailboxFilter::INBOX);
        $this->applyUnread($query, $user);

        return $query->count();
    }

    /** @return Collection<int, MailDraft> the member's own unfinished drafts, newest first, including ones that could not be confirmed */
    public function drafts(User $user): Collection
    {
        $this->authorize($user);

        return MailDraft::query()->where('user_id', $user->getKey())
            ->whereIn('state', [MailDraft::STATE_OPEN, MailDraft::STATE_UNCONFIRMED])
            ->orderByDesc('updated_at')->orderByDesc('id')->get();
    }

    // ---------------------------------------------------------------------------------------------

    private function authorize(User $user): void
    {
        if (! $this->access->canView($user)) {
            throw MailActionRefused::forbidden();
        }
    }

    /** @return Builder<MailThread> */
    private function folderQuery(User $user, string $folder): Builder
    {
        $query = MailThread::query();
        $this->applyFolder($query, $user, $folder);

        return $query;
    }

    /** @param Builder<MailThread> $query */
    private function applyFolder(Builder $query, User $user, string $folder): void
    {
        $conversation = fn () => $query->where('mail_threads.kind', MailThread::KIND_CONVERSATION);
        match ($folder) {
            MailboxFilter::INBOX => $conversation()->whereNull('mail_threads.archived_at')->whereNull('mail_threads.trashed_at')
                ->whereNotNull('mail_threads.last_inbound_at')
                ->where(fn (Builder $open) => $open->whereNull('mail_threads.snoozed_until')->orWhere('mail_threads.snoozed_until', '<=', now())),
            MailboxFilter::ASSIGNED => $conversation()->where('mail_threads.assigned_user_id', $user->getKey())
                ->whereNull('mail_threads.archived_at')->whereNull('mail_threads.trashed_at'),
            MailboxFilter::STARRED => $query->whereNull('mail_threads.trashed_at')
                ->whereExists(fn (QueryBuilder $states) => $states->selectRaw('1')->from('mail_thread_user_states as starred')
                    ->whereColumn('starred.thread_id', 'mail_threads.id')->where('starred.user_id', $user->getKey())->whereNotNull('starred.starred_at')),
            MailboxFilter::DRAFTS => $query->whereNull('mail_threads.trashed_at')
                ->whereExists(fn (QueryBuilder $drafts) => $drafts->selectRaw('1')->from('mail_drafts')
                    ->whereColumn('mail_drafts.thread_id', 'mail_threads.id')->where('mail_drafts.user_id', $user->getKey())
                    ->whereIn('mail_drafts.state', [MailDraft::STATE_OPEN, MailDraft::STATE_UNCONFIRMED])),
            MailboxFilter::SENT => $query->whereNull('mail_threads.trashed_at')
                ->whereExists(fn (QueryBuilder $sent) => $sent->selectRaw('1')->from('mail_thread_messages as sent_members')
                    ->join('outbound_emails as sent_mail', 'sent_mail.id', '=', 'sent_members.outbound_email_id')
                    ->whereColumn('sent_members.thread_id', 'mail_threads.id')->whereIn('sent_mail.source_type', MailThreadIndexer::HUMAN_SOURCES)),
            MailboxFilter::AUTOMATED => $query->where('mail_threads.kind', MailThread::KIND_AUTOMATED),
            MailboxFilter::ARCHIVE => $conversation()->whereNotNull('mail_threads.archived_at')->whereNull('mail_threads.trashed_at'),
            MailboxFilter::TRASH => $conversation()->whereNotNull('mail_threads.trashed_at')
                ->when(! $this->access->canManage($user), fn (Builder $none) => $none->whereRaw('1 = 0')),
            default => throw new InvalidArgumentException('Unknown mail folder.'),
        };
    }

    /** @param Builder<MailThread> $query Unread for $user: marked unread, or newer inbound mail than they have read to and than the backfill baseline */
    private function applyUnread(Builder $query, User $user): void
    {
        $query->where('mail_threads.kind', MailThread::KIND_CONVERSATION)->where(function (Builder $unread) use ($user): void {
            $unread->whereExists(fn (QueryBuilder $marked) => $marked->selectRaw('1')->from('mail_thread_user_states as marked')
                ->whereColumn('marked.thread_id', 'mail_threads.id')->where('marked.user_id', $user->getKey())->whereNotNull('marked.marked_unread_at'))
                ->orWhere(function (Builder $newer) use ($user): void {
                    $newer->whereNotNull('mail_threads.last_inbound_at')
                        ->where(fn (Builder $baseline) => $baseline->whereNull('mail_threads.unread_baseline_at')
                            ->orWhereColumn('mail_threads.last_inbound_at', '>', 'mail_threads.unread_baseline_at'))
                        ->whereNotExists(fn (QueryBuilder $read) => $read->selectRaw('1')->from('mail_thread_user_states as seen')
                            ->whereColumn('seen.thread_id', 'mail_threads.id')->where('seen.user_id', $user->getKey())
                            ->whereNotNull('seen.read_through_at')->whereColumn('seen.read_through_at', '>=', 'mail_threads.last_inbound_at'));
                });
        });
    }

    /** Same rule as applyUnread, for one loaded row that carries the user_* attributes. */
    private function unread(MailThread $thread): bool
    {
        if ($thread->isAutomated()) {
            return false;
        }
        if ($thread->getAttribute('user_marked_unread_at') !== null) {
            return true;
        }
        if ($thread->last_inbound_at === null) {
            return false;
        }
        $threshold = collect([$thread->getAttribute('user_read_through_at'), $thread->unread_baseline_at])
            ->filter()->map(fn ($value) => \Carbon\CarbonImmutable::parse($value))->max();

        return $threshold === null || $thread->last_inbound_at->gt($threshold);
    }

    private function stateColumn(User $user, string $column): QueryBuilder
    {
        return DB::table('mail_thread_user_states')->select($column)
            ->whereColumn('mail_thread_user_states.thread_id', 'mail_threads.id')
            ->where('mail_thread_user_states.user_id', $user->getKey())->limit(1);
    }

    /** @param Builder<MailThread> $query */
    private function applySearch(Builder $query, string $term): void
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $match = static fn (string $column): string => "{$column} {$like} ? ESCAPE '\\'";

        // Bcc is never searched. Notes are internal and are not part of the message.
        $query->where(function (Builder $search) use ($pattern, $match): void {
            $search->whereRaw($match('mail_threads.subject'), [$pattern])
                ->orWhereRaw($match('mail_threads.snippet'), [$pattern])
                ->orWhereRaw($match('CAST(mail_threads.participants AS TEXT)'), [$pattern])
                ->orWhereExists(fn (QueryBuilder $inbound) => $inbound->selectRaw('1')->from('mail_thread_messages as sm')
                    ->join('inbound_emails as si', 'si.id', '=', 'sm.inbound_email_id')
                    ->whereColumn('sm.thread_id', 'mail_threads.id')
                    ->where(fn (QueryBuilder $fields) => $fields
                        ->whereRaw($match('si.subject'), [$pattern])->orWhereRaw($match('si.from_address'), [$pattern])
                        ->orWhereRaw($match('si.from_display_name'), [$pattern])->orWhereRaw($match('si.text_body'), [$pattern])))
                ->orWhereExists(fn (QueryBuilder $outbound) => $outbound->selectRaw('1')->from('mail_thread_messages as sm2')
                    ->join('outbound_emails as so', 'so.id', '=', 'sm2.outbound_email_id')
                    ->whereColumn('sm2.thread_id', 'mail_threads.id')
                    ->where(fn (QueryBuilder $fields) => $fields
                        ->whereRaw($match('so.subject'), [$pattern])->orWhereRaw($match('so.text_body'), [$pattern])
                        ->orWhereRaw($match('CAST(so.to_recipients AS TEXT)'), [$pattern])->orWhereRaw($match('CAST(so.cc_recipients AS TEXT)'), [$pattern])));
        });
    }
}
