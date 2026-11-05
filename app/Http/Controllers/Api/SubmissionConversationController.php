<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\MemberReplies\SubmissionConversationService;
use App\Support\MemberReplies\SubmissionConversationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member side of the conversation on a station request, supply request or service ticket, for the Daily
 * Checkout app (docs/member-replies.md section 2.3). The member who submitted it reads and replies; anyone else
 * gets 403. A record that does not exist is 404. Staff answer from the admin console.
 */
final class SubmissionConversationController extends Controller
{
    public function __construct(private readonly SubmissionConversationService $conversations) {}

    public function show(Request $request, string $type, int $id): JsonResponse
    {
        $user = $this->member($request);
        $subject = $this->subject($type, $id);
        abort_unless(SubmissionConversationAccess::isParticipant($user, $type, $subject), 403, 'Only the member who sent this can read its conversation.');

        return response()->json(['data' => $this->payload($user, $type, $subject)]);
    }

    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $user = $this->member($request);
        $valid = $request->validate([
            'body' => ['required', 'string', 'max:'.SubmissionConversationService::MAX_BODY],
            'client_message_id' => ['required', 'uuid'],
        ]);
        $subject = $this->subject($type, $id);
        abort_unless(SubmissionConversationAccess::isParticipant($user, $type, $subject), 403, 'Only the member who sent this can reply to it.');

        try {
            $this->conversations->postAsMember($user, $type, $id, (string) $valid['body'], (string) $valid['client_message_id']);
        } catch (AuthorizationException) {
            abort(403, 'Only the member who sent this can reply to it.');
        }

        return response()->json(['data' => $this->payload($user, $type, $subject->refresh())], 201);
    }

    /** @return array{type: string, id: int, can_reply: bool, messages: list<array<string, mixed>>} */
    private function payload(User $user, string $type, \Illuminate\Database\Eloquent\Model $subject): array
    {
        return [
            'type' => $type,
            'id' => (int) $subject->getKey(),
            'can_reply' => $subject->getAttribute('archived_at') === null,
            'messages' => $this->conversations->thread($user, $type, $subject),
        ];
    }

    private function subject(string $type, int $id): \Illuminate\Database\Eloquent\Model
    {
        try {
            return $this->conversations->subject($type, $id);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    private function member(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        app(AuthenticatedMemberContextResolver::class)->resolve($request)->actor()->requireEmployee();

        return $user;
    }
}
