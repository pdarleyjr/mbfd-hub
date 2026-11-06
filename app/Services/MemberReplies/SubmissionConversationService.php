<?php

declare(strict_types=1);

namespace App\Services\MemberReplies;

use App\Models\ApparatusServiceTicket;
use App\Models\StationRequest;
use App\Models\StationSupplyRequest;
use App\Models\SubmissionMessage;
use App\Models\User;
use App\Support\MemberReplies\ReplySubject;
use App\Support\MemberReplies\SubmissionConversationAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The conversation on a station request, station supply request or apparatus service ticket
 * (docs/member-replies.md section 2.3). Those workflows already show the member staff's public updates
 * (`public_note`, `public_response`); what they lacked was a way for the member to answer, and for staff to
 * answer back. Both sides write here; staff internal notes stay in each workflow's own internal columns.
 *
 * Idempotent per client_message_id. A member may leave at most MAX_UNANSWERED messages in a row before staff
 * answer, the same guard the EMS request thread uses.
 */
final class SubmissionConversationService
{
    public const MAX_BODY = 4000;

    public const MAX_UNANSWERED = 10;

    public function __construct(
        private readonly MemberReplyLedger $ledger,
        private readonly SubmissionConversationNotifier $notifier,
    ) {}

    /** @throws ModelNotFoundException */
    public function subject(string $type, int $id): Model
    {
        return match ($type) {
            ReplySubject::STATION_REQUEST => StationRequest::query()->findOrFail($id),
            ReplySubject::STATION_SUPPLY_REQUEST => StationSupplyRequest::query()->findOrFail($id),
            ReplySubject::APPARATUS_SERVICE_TICKET => ApparatusServiceTicket::query()->findOrFail($id),
            default => throw new ModelNotFoundException("Unknown conversation type [{$type}]."),
        };
    }

    /**
     * Messages, oldest first, shaped for the viewer. Participants and staff only.
     *
     * @return list<array{id: int, body: string, own: bool, from_staff: bool, author: string, at: string}>
     *
     * @throws AuthorizationException
     */
    public function thread(User $viewer, string $type, Model $subject): array
    {
        $isParticipant = SubmissionConversationAccess::isParticipant($viewer, $type, $subject);
        if (! $isParticipant && ! SubmissionConversationAccess::canStaffRead($viewer, $type, $subject)) {
            throw new AuthorizationException('Only the member who sent this and the staff handling it can read its conversation.');
        }

        return SubmissionMessage::query()
            ->forSubject($type, (int) $subject->getKey())
            ->with('author:id,name,display_name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SubmissionMessage $message): array => $this->present($message, $viewer, ! $isParticipant))
            ->values()
            ->all();
    }

    /**
     * A participant's reply. Archived records are closed to new messages; a completed one is not, so a member can
     * still say "it never arrived".
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function postAsMember(User $member, string $type, int $id, string $body, string $clientMessageId): SubmissionMessage
    {
        $body = $this->cleanBody($body);

        return $this->guardRace($member, $clientMessageId, fn (): SubmissionMessage => DB::transaction(function () use ($member, $type, $id, $body, $clientMessageId): SubmissionMessage {
            $subject = $this->locked($type, $id);
            if (! SubmissionConversationAccess::isParticipant($member, $type, $subject)) {
                throw new AuthorizationException('Only the member who sent this can reply to it.');
            }
            if (($replayed = $this->replayed($member, $clientMessageId)) !== null) {
                return $replayed;
            }
            $this->assertOpenForMessages($subject);
            if ($this->unansweredCount($type, $id) >= self::MAX_UNANSWERED) {
                throw ValidationException::withMessages(['body' => 'Wait for a reply before sending more messages here.']);
            }

            $message = $this->append($type, $id, $member, SubmissionMessage::SIDE_MEMBER, $body, $clientMessageId);
            $this->ledger->memberReplied($type, $id, $member, $message->created_at);
            DB::afterCommit(fn () => $this->notifier->memberReplied($type, $subject, $message, $member));

            return $message;
        }, 3));
    }

    /**
     * Staff answer the member. Always member-visible: an internal note belongs in the workflow's own internal field.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function postAsStaff(User $staff, string $type, int $id, string $body, string $clientMessageId): SubmissionMessage
    {
        $body = $this->cleanBody($body);

        return $this->guardRace($staff, $clientMessageId, fn (): SubmissionMessage => DB::transaction(function () use ($staff, $type, $id, $body, $clientMessageId): SubmissionMessage {
            $subject = $this->locked($type, $id);
            if (! SubmissionConversationAccess::canStaffReply($staff, $type, $subject)) {
                throw new AuthorizationException('You cannot reply on this record.');
            }
            if (($replayed = $this->replayed($staff, $clientMessageId)) !== null) {
                return $replayed;
            }
            $this->assertOpenForMessages($subject);

            $message = $this->append($type, $id, $staff, SubmissionMessage::SIDE_STAFF, $body, $clientMessageId);
            $this->ledger->staffReplied($type, $id, $message->created_at);
            DB::afterCommit(fn () => $this->notifier->staffReplied($type, $subject, $message, $staff));

            return $message;
        }, 3));
    }

    /** @return array{id: int, body: string, own: bool, from_staff: bool, author: string, at: string} */
    private function present(SubmissionMessage $message, User $viewer, bool $forStaff): array
    {
        $own = (int) $message->author_user_id === (int) $viewer->getKey();
        $author = $message->author;
        $name = $author instanceof User ? trim((string) ($author->display_name ?: $author->name)) : '';

        return [
            'id' => (int) $message->getKey(),
            'body' => (string) $message->body,
            'own' => $own,
            'from_staff' => $message->isFromStaff(),
            // Members see the department; staff see who wrote it.
            'author' => $own ? 'You' : ($message->isFromStaff()
                ? ($forStaff && $name !== '' ? $name.' (staff)' : 'Support Services')
                : ($name !== '' ? $name : 'Member')),
            'at' => CarbonImmutable::instance($message->created_at)->toIso8601String(),
        ];
    }

    private function append(string $type, int $id, User $author, string $side, string $body, string $clientMessageId): SubmissionMessage
    {
        return SubmissionMessage::query()->create([
            'subject_type' => $type,
            'subject_id' => $id,
            'author_user_id' => $author->getKey(),
            'author_employee_id' => $author->employee_profile_id,
            'author_side' => $side,
            'body' => $body,
            'client_message_id' => strtolower($clientMessageId),
        ]);
    }

    private function locked(string $type, int $id): Model
    {
        $model = $this->subject($type, $id);

        return $model->newQuery()->lockForUpdate()->findOrFail($model->getKey());
    }

    private function replayed(User $author, string $clientMessageId): ?SubmissionMessage
    {
        $existing = SubmissionMessage::query()->where('client_message_id', strtolower($clientMessageId))->first();
        if ($existing === null) {
            return null;
        }
        if ((int) $existing->author_user_id !== (int) $author->getKey()) {
            throw new AuthorizationException('That message id belongs to another author.');
        }

        return $existing;
    }

    /**
     * @param  callable(): SubmissionMessage  $write
     */
    private function guardRace(User $author, string $clientMessageId, callable $write): SubmissionMessage
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $exception) {
            // Two retries of one message raced; the loser returns the winner's row.
            return $this->replayed($author, $clientMessageId) ?? throw $exception;
        }
    }

    private function assertOpenForMessages(Model $subject): void
    {
        if ($subject->getAttribute('archived_at') !== null) {
            throw ValidationException::withMessages(['body' => 'This record was archived. Start a new request instead.']);
        }
    }

    private function unansweredCount(string $type, int $id): int
    {
        $lastStaff = SubmissionMessage::query()->forSubject($type, $id)->where('author_side', SubmissionMessage::SIDE_STAFF)->max('id');

        return SubmissionMessage::query()->forSubject($type, $id)
            ->where('author_side', SubmissionMessage::SIDE_MEMBER)
            ->when($lastStaff !== null, fn ($query) => $query->where('id', '>', $lastStaff))
            ->count();
    }

    private function cleanBody(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write a message first.']);
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw ValidationException::withMessages(['body' => 'Keep the message under '.self::MAX_BODY.' characters.']);
        }

        return $body;
    }
}
