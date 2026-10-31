<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\DailyRoster\RosterImport;
use App\Models\InboundEmail;
use App\Models\Mail\MailThread;
use App\Models\Mail\MailThreadEvent;
use App\Models\Mail\MailThreadMessage;
use App\Models\OutboundEmail;
use App\Services\DailyRoster\TeleStaffMailClassifier;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Groups inbound_emails and outbound_emails into mail_threads.
 *
 * Threading uses message identity only: parent foreign keys, then In-Reply-To and References matched against
 * the Message-ID the Hub holds for each message (inbound provider_message_id, outbound message_id or
 * provider_message_id). It never uses the subject, because two unrelated conversations often share one.
 *
 * Every entry point is idempotent and order tolerant: indexing the same row twice changes nothing, and a reply
 * indexed before its parent merges with it when the parent arrives (or on the next sweep). The inbound path
 * never depends on this class: it runs after commit and its failures are caught by the caller.
 *
 * TeleStaff protection: a thread holding roster mail is sealed. Roster mail never joins or merges with
 * another thread, and no other message joins it, so a forged In-Reply-To cannot hide a person's message in
 * the read-only Automated folder or freeze a human conversation. Roster mail is whatever has a roster_imports row,
 * and also what the roster pipeline's own classifier recognizes, because mail that arrived while the pipeline was
 * off has no row and must not surface as an ordinary conversation.
 */
final class MailThreadIndexer
{
    /** Outbound source types a person writes in the Email Center (or the older Compose page). */
    public const HUMAN_SOURCES = ['admin_compose', 'admin_reply', 'admin_forward'];

    public function __construct(
        private readonly MailText $text,
        private readonly AddressParser $addresses,
        private readonly TeleStaffMailClassifier $rosterClassifier,
    ) {}

    /**
     * @param  CarbonInterface|null  $readBefore  Backfill only: inbound mail received at or before this moment starts read.
     */
    public function indexInbound(InboundEmail|int $email, ?CarbonInterface $readBefore = null): ?MailThread
    {
        $row = $email instanceof InboundEmail ? InboundEmail::query()->find($email->getKey()) : InboundEmail::query()->find($email);

        return $row === null ? null : $this->place($row, $readBefore);
    }

    public function indexOutbound(OutboundEmail|int $email, ?CarbonInterface $readBefore = null): ?MailThread
    {
        $row = $email instanceof OutboundEmail ? OutboundEmail::query()->find($email->getKey()) : OutboundEmail::query()->find($email);

        return $row === null ? null : $this->place($row, $readBefore);
    }

    /** Re-derives a thread's summary from the messages that still exist. Returns false when none do. */
    public function refresh(MailThread $thread): bool
    {
        return DB::transaction(fn (): bool => $this->recompute($thread->refresh()));
    }

    // ---------------------------------------------------------------------------------------------
    // Placement
    // ---------------------------------------------------------------------------------------------

    private function place(InboundEmail|OutboundEmail $message, ?CarbonInterface $readBefore): ?MailThread
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): ?MailThread => $this->placeOnce($message, $readBefore));
            } catch (UniqueConstraintViolationException $exception) {
                // Two workers indexed related messages at once; the loser re-reads what the winner wrote.
                if ($attempt >= 3) {
                    throw $exception;
                }
            }
        }
    }

    private function placeOnce(InboundEmail|OutboundEmail $message, ?CarbonInterface $readBefore): ?MailThread
    {
        $membership = $this->membership($message);
        $roster = $message instanceof InboundEmail && $this->isRosterInbound($message);

        $candidates = [];
        if ($roster) {
            $candidates = $membership === null ? [] : [$membership->thread_id];
        } else {
            $candidates = $this->relatedThreadIds($message);
            if ($membership !== null) {
                $candidates[] = $membership->thread_id;
            }
            $candidates = array_values(array_unique($candidates));
            $sealed = $this->rosterThreadIds($candidates);
            $candidates = array_values(array_diff($candidates, array_diff($sealed, $membership === null ? [] : [$membership->thread_id])));
        }
        sort($candidates);

        if ($candidates === []) {
            $thread = MailThread::query()->firstOrCreate(
                ['thread_key' => hash('sha256', $this->identity($message))],
                ['kind' => MailThread::KIND_CONVERSATION, 'message_count' => 0],
            );
        } else {
            $threads = MailThread::query()->whereIn('id', $candidates)->orderBy('id')->lockForUpdate()->get();
            $thread = $threads->first();
            if ($thread === null) {
                return null;
            }
            foreach ($threads->slice(1) as $loser) {
                $this->merge($thread, $loser);
            }
        }

        $membership = $this->membership($message);
        $added = false;
        if ($membership === null) {
            MailThreadMessage::query()->create($this->membershipAttributes($message) + ['thread_id' => $thread->getKey()]);
            $added = true;
        } elseif ($membership->thread_id !== $thread->getKey()) {
            $membership->forceFill(['thread_id' => $thread->getKey()])->save();
        }

        $thread->refresh();
        $previousInbound = $thread->last_inbound_at;
        $this->recompute($thread);
        $this->applyInboundRules($thread, $message, $added, $previousInbound, $readBefore);

        return $thread->refresh();
    }

    /**
     * New inbound mail brings an archived, snoozed or trashed conversation back to the Inbox, because a person's
     * reply must never land somewhere nobody looks. The read baseline is a backfill rule for a message's first
     * placement only: re-checking mail that is already indexed never changes who has read it.
     */
    private function applyInboundRules(MailThread $thread, InboundEmail|OutboundEmail $message, bool $added, ?CarbonInterface $previousInbound, ?CarbonInterface $readBefore): void
    {
        if (! $message instanceof InboundEmail) {
            return;
        }
        $received = $message->received_at;
        $updates = [];

        if ($added && ($previousInbound === null || $received->gte($previousInbound))
            && ($thread->archived_at !== null || $thread->snoozed_until !== null || $thread->trashed_at !== null)) {
            $updates['archived_at'] = null;
            $updates['snoozed_until'] = null;
            $updates['trashed_at'] = null;
        }
        if ($added && $readBefore !== null && $received->lte($readBefore)
            && ($thread->unread_baseline_at === null || $received->gt($thread->unread_baseline_at))) {
            $updates['unread_baseline_at'] = $received;
        }
        if ($updates !== []) {
            $thread->forceFill($updates)->save();
        }
    }

    /** @return list<int> */
    private function relatedThreadIds(InboundEmail|OutboundEmail $message): array
    {
        $inboundIds = [];
        $outboundIds = [];

        $parentRefs = $this->normalizedIds([...(array) ($message->references ?? []), $message->in_reply_to]);
        if ($parentRefs !== []) {
            $variants = $this->lookupVariants($parentRefs);
            $inboundIds = [...$inboundIds, ...InboundEmail::query()->whereIn('provider_message_id', $variants)->pluck('id')->all()];
            $outboundIds = [...$outboundIds, ...OutboundEmail::query()
                ->where(fn (Builder $query) => $query->whereIn('message_id', $variants)->orWhereIn('provider_message_id', $variants))
                ->pluck('id')->all()];
        }

        $own = $this->ownIds($message);
        if ($own !== []) {
            $variants = $this->lookupVariants($own);
            $inboundIds = [...$inboundIds, ...InboundEmail::query()
                ->where(function (Builder $query) use ($own, $variants): void {
                    $query->whereIn('in_reply_to', $variants);
                    foreach ($own as $id) {
                        $query->orWhereJsonContains('references', $id);
                    }
                })->pluck('id')->all()];
            $outboundIds = [...$outboundIds, ...OutboundEmail::query()
                ->where(function (Builder $query) use ($own, $variants): void {
                    $query->whereIn('in_reply_to', $variants);
                    foreach ($own as $id) {
                        $query->orWhereJsonContains('references', $id);
                    }
                })->pluck('id')->all()];
        }

        // Foreign keys. A forward starts its own conversation, as it does in every mail client: it carries
        // no threading headers and its recipients are new people.
        if ($message instanceof InboundEmail) {
            if ($message->parent_outbound_email_id !== null) {
                $outboundIds[] = (int) $message->parent_outbound_email_id;
            }
            $replies = OutboundEmail::query()->where('parent_inbound_email_id', $message->getKey())
                ->where('source_type', '!=', 'admin_forward')->pluck('id')->all();
            $outboundIds = [...$outboundIds, ...$replies];
        } else {
            if ($message->source_type !== 'admin_forward') {
                if ($message->parent_inbound_email_id !== null) {
                    $inboundIds[] = (int) $message->parent_inbound_email_id;
                }
                if ($message->parent_outbound_email_id !== null) {
                    $outboundIds[] = (int) $message->parent_outbound_email_id;
                }
            }
            $inboundIds = [...$inboundIds, ...InboundEmail::query()->where('parent_outbound_email_id', $message->getKey())->pluck('id')->all()];
            $outboundIds = [...$outboundIds, ...OutboundEmail::query()->where('parent_outbound_email_id', $message->getKey())
                ->where('source_type', '!=', 'admin_forward')->pluck('id')->all()];
        }

        $inboundIds = array_values(array_unique(array_map('intval', $inboundIds)));
        $outboundIds = array_values(array_unique(array_map('intval', $outboundIds)));

        // The message itself is excluded; its own membership is added by the caller.
        if ($message instanceof InboundEmail) {
            $inboundIds = array_values(array_diff($inboundIds, [$message->getKey()]));
        } else {
            $outboundIds = array_values(array_diff($outboundIds, [$message->getKey()]));
        }

        return MailThreadMessage::query()
            ->where(function (Builder $query) use ($inboundIds, $outboundIds): void {
                $query->whereIn('inbound_email_id', $inboundIds)->orWhereIn('outbound_email_id', $outboundIds);
            })
            ->distinct()->pluck('thread_id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @param list<int> $threadIds @return list<int> thread ids that hold roster mail */
    private function rosterThreadIds(array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        $members = MailThreadMessage::query()->whereIn('thread_id', $threadIds)->whereNotNull('inbound_email_id')->get(['thread_id', 'inbound_email_id']);
        $roster = $this->rosterInboundIds($members->pluck('inbound_email_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return $members->whereIn('inbound_email_id', $roster)->pluck('thread_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    private function isRosterInbound(InboundEmail $email): bool
    {
        return $this->rosterInboundIds([(int) $email->getKey()]) !== [];
    }

    /**
     * @param  list<int>  $inboundIds
     * @return list<int> the ones that are roster mail, by the same test RosterImportRegistrar uses to write its row
     */
    private function rosterInboundIds(array $inboundIds): array
    {
        if ($inboundIds === []) {
            return [];
        }
        $registered = RosterImport::query()->whereIn('inbound_email_id', $inboundIds)->pluck('inbound_email_id')->map(fn ($id): int => (int) $id)->all();
        $recognized = InboundEmail::query()->whereIn('id', array_values(array_diff($inboundIds, $registered)))->get()
            ->reject(fn (InboundEmail $email): bool => $this->rosterClassifier->classify($email)->isOrdinary())
            ->map(fn (InboundEmail $email): int => (int) $email->getKey())->values()->all();

        return [...$registered, ...$recognized];
    }

    private function merge(MailThread $survivor, MailThread $loser): void
    {
        MailThreadMessage::query()->where('thread_id', $loser->getKey())->update(['thread_id' => $survivor->getKey()]);

        foreach (DB::table('mail_thread_user_states')->where('thread_id', $loser->getKey())->get() as $state) {
            $mine = DB::table('mail_thread_user_states')->where('thread_id', $survivor->getKey())->where('user_id', $state->user_id)->first();
            if ($mine === null) {
                DB::table('mail_thread_user_states')->where('id', $state->id)->update(['thread_id' => $survivor->getKey()]);

                continue;
            }
            DB::table('mail_thread_user_states')->where('id', $mine->id)->update([
                'read_through_at' => $this->latest($mine->read_through_at, $state->read_through_at),
                'starred_at' => $this->latest($mine->starred_at, $state->starred_at),
                'marked_unread_at' => $this->latest($mine->marked_unread_at, $state->marked_unread_at),
            ]);
        }

        foreach (DB::table('mail_thread_labels')->where('thread_id', $loser->getKey())->get() as $label) {
            DB::table('mail_thread_labels')->insertOrIgnore([
                'thread_id' => $survivor->getKey(), 'label_id' => $label->label_id,
                'created_by_user_id' => $label->created_by_user_id, 'created_at' => $label->created_at, 'updated_at' => $label->updated_at,
            ]);
        }
        DB::table('mail_thread_notes')->where('thread_id', $loser->getKey())->update(['thread_id' => $survivor->getKey()]);
        DB::table('mail_thread_events')->where('thread_id', $loser->getKey())->update(['thread_id' => $survivor->getKey()]);
        DB::table('mail_drafts')->where('thread_id', $loser->getKey())->update(['thread_id' => $survivor->getKey()]);

        // A merged conversation is only archived, trashed or snoozed if every part of it was.
        $both = [$survivor, $loser];
        $allArchived = collect($both)->every(fn (MailThread $thread): bool => $thread->archived_at !== null);
        $allTrashed = collect($both)->every(fn (MailThread $thread): bool => $thread->trashed_at !== null);
        $allSnoozed = collect($both)->every(fn (MailThread $thread): bool => $thread->snoozed_until !== null);
        $survivor->forceFill([
            'archived_at' => $allArchived ? $survivor->archived_at : null,
            'trashed_at' => $allTrashed ? $survivor->trashed_at : null,
            'snoozed_until' => $allSnoozed ? collect($both)->max(fn (MailThread $thread) => $thread->snoozed_until) : null,
            'assigned_user_id' => $survivor->assigned_user_id ?? $loser->assigned_user_id,
            'priority' => $survivor->priority === MailThread::PRIORITY_HIGH || $loser->priority === MailThread::PRIORITY_HIGH
                ? MailThread::PRIORITY_HIGH : MailThread::PRIORITY_NORMAL,
            'unread_baseline_at' => collect($both)->max(fn (MailThread $thread) => $thread->unread_baseline_at),
        ])->save();

        MailThreadEvent::query()->create([
            'thread_id' => $survivor->getKey(), 'user_id' => null, 'type' => 'merged',
            'metadata' => ['merged_thread_id' => $loser->getKey()],
        ]);
        $loser->delete();
    }

    private function latest(mixed $a, mixed $b): mixed
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return strcmp((string) $a, (string) $b) >= 0 ? $a : $b;
    }

    // ---------------------------------------------------------------------------------------------
    // Summary
    // ---------------------------------------------------------------------------------------------

    /** Recomputes everything derivable from the messages. Returns false (and changes nothing) with no messages. */
    private function recompute(MailThread $thread): bool
    {
        $members = MailThreadMessage::query()->where('thread_id', $thread->getKey())->orderBy('message_at')->orderBy('id')->get();
        $inbounds = InboundEmail::query()->whereIn('id', $members->pluck('inbound_email_id')->filter())
            ->get(['id', 'provider_message_id', 'from_address', 'from_display_name', 'to_address', 'subject', 'received_at', 'safe_headers', 'attachment_metadata'])
            ->keyBy('id');
        $outbounds = OutboundEmail::query()->whereIn('id', $members->pluck('outbound_email_id')->filter())
            ->get(['id', 'source_type', 'from_address', 'to_recipients', 'cc_recipients', 'subject', 'attachment_metadata', 'queued_at', 'created_at'])
            ->keyBy('id');

        $rows = [];
        foreach ($members as $member) {
            if ($member->inbound_email_id !== null && $inbounds->has($member->inbound_email_id)) {
                $rows[] = ['at' => $member->message_at, 'inbound' => $inbounds[$member->inbound_email_id], 'outbound' => null];
            } elseif ($member->outbound_email_id !== null && $outbounds->has($member->outbound_email_id)) {
                $rows[] = ['at' => $member->message_at, 'inbound' => null, 'outbound' => $outbounds[$member->outbound_email_id]];
            }
        }
        if ($rows === []) {
            return false;
        }

        $inboundIds = collect($rows)->pluck('inbound.id')->filter()->all();
        $rosterBacked = $this->rosterInboundIds(array_values(array_map('intval', $inboundIds))) !== [];
        $hasOrdinaryInbound = collect($rows)->contains(fn (array $row): bool => $row['inbound'] !== null);
        $hasHumanOutbound = collect($rows)->contains(fn (array $row): bool => $row['outbound'] !== null && in_array($row['outbound']->source_type, self::HUMAN_SOURCES, true));
        $kind = $rosterBacked || (! $hasOrdinaryInbound && ! $hasHumanOutbound)
            ? MailThread::KIND_AUTOMATED : MailThread::KIND_CONVERSATION;

        $first = $rows[0];
        $last = $rows[array_key_last($rows)];
        $lastInbound = collect($rows)->filter(fn (array $row): bool => $row['inbound'] !== null)->map(fn (array $row) => $row['inbound']->received_at)->max();
        $subject = ($first['inbound'] ?? $first['outbound'])->subject;
        $attachments = collect($rows)->contains(fn (array $row): bool => ! empty(($row['inbound'] ?? $row['outbound'])->attachment_metadata));

        $newest = $last['inbound'] !== null
            ? InboundEmail::query()->find($last['inbound']->id, ['id', 'text_body', 'sanitized_html_body'])
            : OutboundEmail::query()->find($last['outbound']->id, ['id', 'text_body', 'html_body']);
        $snippet = $newest instanceof InboundEmail
            ? $this->text->snippet($newest->text_body, $newest->sanitized_html_body)
            : $this->text->snippet($newest?->text_body, $newest?->html_body);

        $thread->forceFill([
            'kind' => $kind,
            'subject' => mb_substr($this->text->baseSubject($subject) ?: '(no subject)', 0, 998),
            'snippet' => $snippet === '' ? null : $snippet,
            'participants' => $this->participants($rows),
            'message_count' => count($rows),
            'has_attachments' => $attachments,
            'first_message_at' => $first['at'],
            'last_message_at' => $last['at'],
            'last_inbound_at' => $lastInbound,
        ])->save();

        return true;
    }

    /**
     * Everyone who wrote or was written to in the open: From, To and Cc. Never Bcc, and never the Hub's own
     * addresses, which would head every list.
     *
     * @param  list<array{at: mixed, inbound: ?InboundEmail, outbound: ?OutboundEmail}>  $rows
     * @return list<array{address: string, name: ?string}>
     */
    private function participants(array $rows): array
    {
        $own = array_filter([
            strtolower((string) config('communications.cloudflare.from_address')),
            strtolower((string) config('communications.inbound.address')),
        ]);
        $collected = [];
        foreach ($rows as $row) {
            if ($row['inbound'] !== null) {
                $email = $row['inbound'];
                $collected[] = ['address' => $email->from_address, 'name' => $email->from_display_name];
                array_push($collected, ...$this->addresses->parse([$email->to_address, $email->safe_headers['to'] ?? [], $email->safe_headers['cc'] ?? []]));
            } else {
                $email = $row['outbound'];
                array_push($collected, ...$this->addresses->parse([$email->from_address, $email->to_recipients ?? [], $email->cc_recipients ?? []]));
            }
        }

        return array_slice(array_values(array_filter(
            $this->addresses->parse($collected),
            static fn (array $participant): bool => ! in_array($participant['address'], $own, true),
        )), 0, 30);
    }

    // ---------------------------------------------------------------------------------------------
    // Identity
    // ---------------------------------------------------------------------------------------------

    private function membership(InboundEmail|OutboundEmail $message): ?MailThreadMessage
    {
        return MailThreadMessage::query()
            ->where($message instanceof InboundEmail ? 'inbound_email_id' : 'outbound_email_id', $message->getKey())
            ->first();
    }

    /** @return array{inbound_email_id: ?int, outbound_email_id: ?int, message_at: mixed} */
    private function membershipAttributes(InboundEmail|OutboundEmail $message): array
    {
        return $message instanceof InboundEmail
            ? ['inbound_email_id' => $message->getKey(), 'outbound_email_id' => null, 'message_at' => $message->received_at]
            : ['inbound_email_id' => null, 'outbound_email_id' => $message->getKey(), 'message_at' => $message->queued_at ?? $message->created_at ?? now()];
    }

    private function identity(InboundEmail|OutboundEmail $message): string
    {
        return $message instanceof InboundEmail ? 'in:'.$message->provider_message_id : 'out:'.$message->getKey();
    }

    /** @return list<string> bracketed Message-IDs this message answers to */
    private function ownIds(InboundEmail|OutboundEmail $message): array
    {
        return $this->normalizedIds($message instanceof InboundEmail
            ? [$message->provider_message_id]
            : [$message->message_id, $message->provider_message_id]);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string> unique "<id@host>" strings; anything with whitespace or control bytes is not an id
     */
    private function normalizedIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '' || strlen($value) > 998 || preg_match('/[\s\x00-\x1F\x7F]/', $value) === 1) {
                continue;
            }
            $ids[] = str_starts_with($value, '<') && str_ends_with($value, '>') ? $value : '<'.trim($value, '<>').'>';
        }

        return array_values(array_unique($ids));
    }

    /**
     * Stored ids are bracketed in most places but a provider may hand back a bare one; match both spellings.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function lookupVariants(array $ids): array
    {
        return array_values(array_unique(array_merge($ids, array_map(static fn (string $id): string => trim($id, '<>'), $ids))));
    }
}
