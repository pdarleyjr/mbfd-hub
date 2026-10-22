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
 * JSON API of the 300 page's Communications tab. Routes are guarded by the
 * single `command.300.access` ability; the service re-checks it on every call.
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
        $feed = $this->service->feed($user, $params['before'] ?? null, isset($params['limit']) ? (int) $params['limit'] : null);

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

        return $this->json([
            'unread_count' => $this->service->unreadCount($user),
            'latest_activity_at' => $this->service->latestActivityAt()?->toIso8601String(),
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
            CommandCommunicationPost::CONTEXT_COMMAND,
            $data['subject'] ?? null,
            (string) ($data['body'] ?? ''),
            $data['parent_id'] ?? null,
            $data['client_submission_id'],
            $request->file('attachments', []) ?? [],
        );

        return $this->json(['data' => $this->presenter->post($post, $user)], $post->wasRecentlyCreated ? 201 : 200);
    }

    public function pin(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['pinned' => ['required', 'boolean']]);
        $user = $this->user($request);
        $post = $this->service->findTopLevel($user, $publicId);
        abort_if($post === null, 404);

        $post = $this->service->setPinned($user, $post, (bool) $data['pinned']);

        return $this->json(['data' => $this->presenter->post($post, $user)]);
    }

    public function read(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $readAt = $this->service->markRead($user);

        return $this->json([
            'unread_count' => $this->service->unreadCount($user),
            'last_read_at' => $readAt->toIso8601String(),
        ]);
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
