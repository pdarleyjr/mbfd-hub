<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApparatusDefect;
use App\Models\User;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\Inspections\IssueMessageService;
use App\Services\Inspections\MemberInspectionConversation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The conversation on one equipment issue, from the Daily Checkout issue page (docs/member-replies.md section 2.1).
 *
 * Only the issue's participants read and write it: the member who reported it, members who re-reported or
 * acknowledged it on a later checkout of the same truck, stretcher reporters, and members who already replied.
 * The rule is IssueMessageService::followsDefect, the same one the employee portal uses. Anyone else gets 403.
 * Members only ever receive member-visible messages; a member can only ever write member-visible ones.
 */
final class MemberIssueConversationController extends Controller
{
    public function __construct(
        private readonly IssueMessageService $messages,
        private readonly MemberInspectionConversation $conversation,
    ) {}

    public function show(Request $request, ApparatusDefect $defect): JsonResponse
    {
        $user = $this->authorizeMember($request);

        try {
            $thread = $this->messages->memberThreadForDefect($user, $defect);
        } catch (AuthorizationException) {
            abort(403, 'Only the members on this issue can read its conversation.');
        }

        return response()->json(['data' => $this->payload($user, $defect, $thread)]);
    }

    public function store(Request $request, ApparatusDefect $defect): JsonResponse
    {
        $user = $this->authorizeMember($request);
        $valid = $request->validate([
            'body' => ['required', 'string', 'max:'.IssueMessageService::MAX_BODY],
            'client_message_id' => ['required', 'uuid'],
        ]);
        abort_unless($this->messages->followsDefect($user, $defect), 403, 'Only the members on this issue can reply to it.');

        try {
            // Always member-visible: this surface never writes an internal note, whoever is signed in.
            $this->messages->post($user, [
                'defect_id' => (int) $defect->getKey(),
                'visibility' => \App\Models\ApparatusIssueMessage::VISIBILITY_MEMBER,
                'body' => (string) $valid['body'],
                'client_message_id' => strtolower((string) $valid['client_message_id']),
            ]);
        } catch (AuthorizationException) {
            abort(403, 'Only the members on this issue can reply to it.');
        }

        return response()->json(['data' => $this->payload($user, $defect, $this->messages->memberThreadForDefect($user, $defect))], 201);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\ApparatusIssueMessage>  $thread
     * @return array{issue_id: int, can_reply: bool, messages: list<array<string, mixed>>}
     */
    private function payload(User $user, ApparatusDefect $defect, $thread): array
    {
        return [
            'issue_id' => (int) $defect->getKey(),
            'can_reply' => true,
            'messages' => array_map(static fn (array $message): array => [
                'id' => $message['id'],
                'body' => $message['body'],
                'own' => $message['own'],
                'from_staff' => $message['from_logistics'] || $message['from_captain5'] || $message['from_assistant'],
                'author' => $message['author'],
                'at' => $message['at']->toIso8601String(),
            ], $this->conversation->present($thread, $user)),
        ];
    }

    /** Same rule as ApparatusIssueHistoryController::authorizeMember. */
    private function authorizeMember(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if (! $user->canManageFleetSwaps()) {
            app(AuthenticatedMemberContextResolver::class)->resolve($request)->actor()->requireEmployee();
        }

        return $user;
    }
}
