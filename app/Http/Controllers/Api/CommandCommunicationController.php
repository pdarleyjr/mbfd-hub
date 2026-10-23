<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommandCommunicationPost;
use App\Models\User;
use App\Services\CommandCommunications\CommandCommunicationPresenter;
use App\Services\CommandCommunications\CommandCommunicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON API of the shared conversation. Two route groups reuse this controller: the 300 page's
 * Communications tab (`/api/command-communications`, gate `access-command-300`) and the
 * Captain 5 page's Messages tab (`/api/captain5/communications`, gate `captain5-view`, route
 * default `channel = captain_5`). The service re-checks the channel on every call.
 */
final class CommandCommunicationController extends Controller
{
    public function __construct(
        private readonly CommandCommunicationService $service,
        private readonly CommandCommunicationPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'before' => ['nullable', 'string', 'size:26'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('command_communications.max_page_size', 50)],
        ]);
        $user = $this->user($request);
        $feed = $this->service->feed($user, $params['before'] ?? null, isset($params['limit']) ? (int) $params['limit'] : null, $this->channel($request));

        return $this->json([
            'data' => $this->presenter->posts($feed['posts'], $user),
            'pinned' => $this->presenter->posts($feed['pinned'], $user),
            'next_before' => $feed['next_before'],
            'unread_count' => $feed['unread_count'],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $channel = $this->channel($request);
        $this->service->assertCanRead($user, $channel); // includes the Captain 5 kill switch

        return $this->json([
            'unread_count' => $this->service->unreadCount($user, $channel),
            'latest_activity_at' => $this->service->latestActivityAt($user, $channel)?->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $limit = (int) config('command_communications.max_attachment_bytes', 15 * 1024 * 1024);
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:'.(int) config('command_communications.subject_max_length', 160)],
            'body' => ['nullable', 'string', 'max:'.(int) config('command_communications.body_max_length', 10000)],
            'parent_id' => ['nullable', 'string', 'size:26'],
            'client_submission_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/D'],
            'attachments' => ['nullable', 'array', 'max:'.(int) config('command_communications.max_attachments', 5)],
            'attachments.*' => ['file', 'max:'.(int) ceil($limit / 1024)],
        ]);
        $user = $this->user($request);

        $post = $this->service->post(
            $user,
            $this->channel($request) === CommandCommunicationPost::CHANNEL_CAPTAIN_5
                ? CommandCommunicationPost::CONTEXT_CAPTAIN5
                : CommandCommunicationPost::CONTEXT_COMMAND,
            $data['subject'] ?? null,
            (string) ($data['body'] ?? ''),
            $data['parent_id'] ?? null,
            $data['client_submission_id'],
            \Illuminate\Support\Arr::wrap($request->file('attachments', [])),
        );

        return $this->json(['data' => $this->presenter->post($post, $user)], $post->wasRecentlyCreated ? 201 : 200);
    }

    public function pin(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['pinned' => ['required', 'boolean']]);
        $user = $this->user($request);
        $channel = $this->channel($request);
        $post = $this->service->findTopLevel($user, $publicId, $channel);
        abort_if($post === null, 404);

        $post = $this->service->setPinned($user, $post, (bool) $data['pinned'], $channel);

        return $this->json(['data' => $this->presenter->post($post, $user)]);
    }

    /** Controlled removal: the author or a communications administrator, with a required reason. */
    public function destroy(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $user = $this->user($request);
        $channel = $this->channel($request);
        $post = $this->service->find($user, $publicId, $channel);
        abort_if($post === null, 404);

        $post = $this->service->delete($user, $post, $data['reason'], $channel);

        return $this->json(['data' => $this->presenter->post($post, $user, $post->parent_id === null)]);
    }

    public function read(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $channel = $this->channel($request);
        $readAt = $this->service->markRead($user, $channel);

        return $this->json([
            'unread_count' => $this->service->unreadCount($user, $channel),
            'last_read_at' => $readAt->toIso8601String(),
        ]);
    }

    /** The channel this route group serves: the route default, else the 300 channel. */
    private function channel(Request $request): string
    {
        $channel = $request->route('channel');

        return $channel === CommandCommunicationPost::CHANNEL_CAPTAIN_5
            ? CommandCommunicationPost::CHANNEL_CAPTAIN_5
            : CommandCommunicationPost::CHANNEL_COMMAND_300;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @param  array<string, mixed>  $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, ['Cache-Control' => 'private, no-store']);
    }
}
