<?php

declare(strict_types=1);

namespace App\Services\MemberReplies;

use App\Models\SubmissionReplyState;
use App\Models\User;
use App\Support\MemberReplies\ReplySubject;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * "A member replied and staff have not answered yet", for every workflow (docs/member-replies.md section 4).
 *
 * Each workflow's own write path calls memberReplied() when a participant posts member-visible text and
 * staffReplied() when a person on staff answers the member (an internal note is not an answer, and neither is
 * the automated Support Services receipt). The marks are written after the surrounding transaction commits, so
 * a rolled-back reply never flags anything and a failure here can never undo the reply itself.
 *
 * Dashboards read awaiting() / stateFor(); they never write.
 */
final class MemberReplyLedger
{
    public function memberReplied(string $type, int $id, ?User $member = null, ?DateTimeInterface $at = null): void
    {
        $this->guardType($type);
        $at ??= now();
        $userId = $member?->getKey();

        DB::afterCommit(fn () => $this->write($type, $id, [
            'awaiting_staff_reply' => true,
            'last_member_reply_at' => $at,
            'last_member_reply_user_id' => $userId,
        ]));
    }

    public function staffReplied(string $type, int $id, ?DateTimeInterface $at = null): void
    {
        $this->guardType($type);
        $at ??= now();

        DB::afterCommit(fn () => $this->write($type, $id, [
            'awaiting_staff_reply' => false,
            'last_staff_reply_at' => $at,
        ]));
    }

    /**
     * Every submission waiting on staff, optionally of some types, oldest member reply first.
     *
     * @param  string|list<string>|null  $types  ReplySubject keys
     * @return Builder<SubmissionReplyState>
     */
    public function awaiting(string|array|null $types = null): Builder
    {
        return SubmissionReplyState::query()
            ->awaitingStaff()
            ->when($types !== null, fn (Builder $query) => $query->ofType($types))
            ->orderBy('last_member_reply_at')
            ->orderBy('id');
    }

    public function isAwaiting(string $type, int $id): bool
    {
        return SubmissionReplyState::query()->ofType($type)->where('subject_id', $id)->awaitingStaff()->exists();
    }

    /**
     * Reply state for many rows of one type in one query (ids without a row are omitted).
     *
     * @param  list<int>  $ids
     * @return array<int, SubmissionReplyState>
     */
    public function stateFor(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return SubmissionReplyState::query()->ofType($type)->whereIn('subject_id', $ids)->get()
            ->keyBy(static fn (SubmissionReplyState $state): int => (int) $state->subject_id)
            ->all();
    }

    /** @param array<string, mixed> $values */
    private function write(string $type, int $id, array $values): void
    {
        try {
            $now = now();
            // insertOrIgnore keeps two concurrent first replies from tripping the unique index on PostgreSQL.
            DB::table('submission_reply_states')->insertOrIgnore([
                'subject_type' => $type,
                'subject_id' => $id,
                'awaiting_staff_reply' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('submission_reply_states')
                ->where('subject_type', $type)
                ->where('subject_id', $id)
                ->update($values + ['updated_at' => $now]);
        } catch (\Throwable $exception) {
            // The reply is already saved; a missing "awaiting" mark must never surface as a failed send.
            report($exception);
        }
    }

    private function guardType(string $type): void
    {
        if (! in_array($type, ReplySubject::all(), true)) {
            throw new InvalidArgumentException("Unknown reply subject [{$type}].");
        }
    }
}
