<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DepartmentUpdateStatus;
use App\Http\Controllers\Controller;
use App\Models\DepartmentUpdate;
use App\Models\User;
use App\Services\DepartmentUpdates\CommandDepartmentUpdatePublisher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * "Post to Department Updates" from the 300 and Captain 5 pages. Two route groups reuse this
 * controller; the route default `origin` records which page a post came from. Who may post is one
 * rule (CommandDepartmentUpdatePublisher::canPost), checked on every call.
 */
final class CommandDepartmentUpdateController extends Controller
{
    public function index(Request $request, CommandDepartmentUpdatePublisher $publisher): JsonResponse
    {
        $user = $this->user($request);
        $origin = $this->origin($request);
        if (! $publisher->canPost($user)) {
            throw new AuthorizationException;
        }

        return $this->json([
            'updates' => $publisher->recent($origin)->map(fn (DepartmentUpdate $update): array => $this->present($update, $user))->values()->all(),
        ]);
    }

    public function store(Request $request, CommandDepartmentUpdatePublisher $publisher): JsonResponse
    {
        $user = $this->user($request);
        $image = $request->file('image');
        $attachment = $request->file('attachment');
        $update = $publisher->publish(
            $user,
            $this->origin($request),
            $request->only(['title', 'body', 'category', 'expires_at', 'client_request_id']),
            $image instanceof UploadedFile ? $image : null,
            $attachment instanceof UploadedFile ? $attachment : null,
        );

        return $this->json(['update' => $this->present($update->load('author:id,name'), $user)], 201);
    }

    public function takeDown(Request $request, CommandDepartmentUpdatePublisher $publisher, int $update): JsonResponse
    {
        $user = $this->user($request);
        $model = $publisher->takeDown($user, $update, $this->origin($request));

        return $this->json(['update' => $this->present($model->load('author:id,name'), $user)]);
    }

    /** @return array<string, mixed> */
    private function present(DepartmentUpdate $update, User $viewer): array
    {
        $live = $update->status === DepartmentUpdateStatus::Published;

        return [
            'id' => (int) $update->getKey(),
            'title' => (string) $update->title,
            'excerpt' => $update->excerpt(160),
            'status' => $live ? 'published' : 'taken_down',
            'posted_at' => $update->publish_at?->utc()->toIso8601String(),
            'expires_at' => $update->expires_at?->utc()->toIso8601String(),
            'author' => $update->attribution() ?? 'A former member',
            'author_rank' => $update->author_rank,
            'author_name' => $update->author_name,
            'own' => (int) $update->author_id === (int) $viewer->getKey(),
            'has_image' => $update->image_path !== null,
            'has_attachment' => $update->attachment_path !== null,
            'url' => route('updates.show', $update, false),
        ];
    }

    private function origin(Request $request): string
    {
        return $request->route('origin') === CommandDepartmentUpdatePublisher::ORIGIN_COMMAND_300
            ? CommandDepartmentUpdatePublisher::ORIGIN_COMMAND_300
            : CommandDepartmentUpdatePublisher::ORIGIN_CAPTAIN_5;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, ['Cache-Control' => 'private, no-store']);
    }
}
