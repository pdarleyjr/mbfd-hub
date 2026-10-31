<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\InboundEmail;
use App\Models\Mail\MailDraft;
use App\Models\Mail\MailThread;
use App\Models\OutboundEmail;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The safety net behind the after-commit hooks, and the backfill. Safe to run at any time, any number of
 * times, concurrently with live mail:
 *
 *  - indexes any message that has no thread yet (a hook failed, or the mail predates the Email Center);
 *  - re-checks recent messages, so a reply indexed before its parent merges once the parent is known;
 *  - repairs threads whose message count no longer matches (TeleStaff cleanup hard-deletes roster mail) and
 *    prunes the ones left empty;
 *  - settles drafts stuck in "sending" by asking the send ledger what really happened (never by resending);
 *  - removes old sent drafts.
 *
 * Nothing here touches inbound_emails or outbound_emails, and nothing logs an address, subject or body.
 */
final class MailIndexSweeper
{
    private const RECENT_HOURS = 48;

    private const STUCK_DRAFT_MINUTES = 15;

    private const SENT_DRAFT_DAYS = 14;

    public function __construct(private readonly MailThreadIndexer $indexer) {}

    /**
     * @param  CarbonInterface|null  $readBefore  inbound mail received at or before this moment starts read (backfill)
     * @param  bool  $everything  re-evaluate every message, not just unindexed and recent ones
     * @return array{indexed: int, rechecked: int, repaired: int, pruned: int, drafts_settled: int, drafts_pruned: int, errors: int}
     */
    public function run(?CarbonInterface $readBefore = null, bool $everything = false): array
    {
        $stats = ['indexed' => 0, 'rechecked' => 0, 'repaired' => 0, 'pruned' => 0, 'drafts_settled' => 0, 'drafts_pruned' => 0, 'errors' => 0];
        $recentSince = now()->subHours(self::RECENT_HOURS);

        InboundEmail::query()->select('id')->orderBy('id')->chunkById(200, function ($rows) use (&$stats, $readBefore, $everything, $recentSince): void {
            $indexed = DB::table('mail_thread_messages')->whereIn('inbound_email_id', $rows->pluck('id'))->pluck('inbound_email_id')->flip();
            $recent = $everything ? $rows->pluck('id')->flip()
                : InboundEmail::query()->whereIn('id', $rows->pluck('id'))->where('created_at', '>=', $recentSince)->pluck('id')->flip();
            foreach ($rows as $row) {
                $isIndexed = $indexed->has($row->id);
                if ($isIndexed && ! $recent->has($row->id)) {
                    continue;
                }
                $this->guard($stats, 'inbound', $row->id, fn () => $this->indexer->indexInbound($row->id, $readBefore));
                $stats[$isIndexed ? 'rechecked' : 'indexed']++;
            }
        });

        OutboundEmail::query()->select('id')->orderBy('id')->chunkById(200, function ($rows) use (&$stats, $readBefore, $everything, $recentSince): void {
            $indexed = DB::table('mail_thread_messages')->whereIn('outbound_email_id', $rows->pluck('id'))->pluck('outbound_email_id')->flip();
            $recent = $everything ? $rows->pluck('id')->flip()
                : OutboundEmail::query()->whereIn('id', $rows->pluck('id'))->where('updated_at', '>=', $recentSince)->pluck('id')->flip();
            foreach ($rows as $row) {
                $isIndexed = $indexed->has($row->id);
                if ($isIndexed && ! $recent->has($row->id)) {
                    continue;
                }
                $this->guard($stats, 'outbound', $row->id, fn () => $this->indexer->indexOutbound($row->id, $readBefore));
                $stats[$isIndexed ? 'rechecked' : 'indexed']++;
            }
        });

        $this->repairThreads($stats);
        $this->settleStuckDrafts($stats);
        $stats['drafts_pruned'] = $this->pruneSentDrafts();

        return $stats;
    }

    private function pruneSentDrafts(): int
    {
        $pruned = 0;
        foreach (MailDraft::query()->where('state', MailDraft::STATE_SENT)->where('updated_at', '<', now()->subDays(self::SENT_DRAFT_DAYS))->orderBy('id')->get() as $draft) {
            $this->deleteFiles($draft);
            $draft->delete();
            $pruned++;
        }

        return $pruned;
    }

    /** The dispatcher keeps its own copy of what was sent; a member's upload stays only as long as its draft is open. */
    private function deleteFiles(MailDraft $draft): void
    {
        foreach ($draft->attachments()->get() as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }
    }

    /** @param array<string, int> $stats */
    private function repairThreads(array &$stats): void
    {
        MailThread::query()
            ->whereRaw('message_count != (select count(*) from mail_thread_messages where mail_thread_messages.thread_id = mail_threads.id)')
            ->orderBy('id')
            ->chunkById(100, function ($threads) use (&$stats): void {
                foreach ($threads as $thread) {
                    try {
                        // Empty means every message was hard-deleted (roster cleanup); the thread has nothing left to show.
                        if (! $this->indexer->refresh($thread)) {
                            $thread->delete();
                            $stats['pruned']++;
                        } else {
                            $stats['repaired']++;
                        }
                    } catch (Throwable $exception) {
                        $this->report($stats, 'thread', $thread->getKey(), $exception);
                    }
                }
            });

        // Threads with no membership at all never match the count query when message_count is already 0.
        $orphans = (int) MailThread::query()
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('mail_thread_messages')->whereColumn('mail_thread_messages.thread_id', 'mail_threads.id'))
            ->delete();
        $stats['pruned'] += $orphans;
    }

    /** @param array<string, int> $stats */
    private function settleStuckDrafts(array &$stats): void
    {
        MailDraft::query()->where('state', MailDraft::STATE_SENDING)
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_DRAFT_MINUTES))
            ->orderBy('id')->each(function (MailDraft $draft) use (&$stats): void {
                $outbound = OutboundEmail::query()->where('source_id', $draft->ledgerSourceId())
                    ->whereIn('source_type', MailThreadIndexer::HUMAN_SOURCES)->orderByDesc('id')->first();
                // A row that may have reached the provider is never resent and never guessed at. No row, or a row
                // proving the provider never took it, means the draft simply goes back to its author.
                $draft->forceFill($outbound === null || MailDraftService::nothingWasSent($outbound)
                    ? ['state' => MailDraft::STATE_OPEN]
                    : ['state' => $outbound->accepted_at !== null ? MailDraft::STATE_SENT : MailDraft::STATE_UNCONFIRMED, 'outbound_email_id' => $outbound->getKey()])->save();
                if ($draft->state === MailDraft::STATE_SENT) {
                    $this->deleteFiles($draft);
                }
                $stats['drafts_settled']++;
            });
    }

    /** @param array<string, int> $stats */
    private function guard(array &$stats, string $kind, int $id, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            $this->report($stats, $kind, $id, $exception);
        }
    }

    /** @param array<string, int> $stats */
    private function report(array &$stats, string $kind, int $id, Throwable $exception): void
    {
        $stats['errors']++;
        // Class and row id only: an exception message can embed bound values, which are addresses and subjects.
        Log::warning('mail_index_sweep_failed', ['kind' => $kind, 'id' => $id, 'exception' => $exception::class]);
    }
}
