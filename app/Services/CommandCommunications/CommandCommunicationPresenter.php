<?php

declare(strict_types=1);

namespace App\Services\CommandCommunications;

use App\Models\CommandCommunicationAttachment;
use App\Models\CommandCommunicationPost;
use App\Models\User;

/**
 * Turns posts into the public JSON shape of the 300 Command conversation API.
 * Exposes only display data: never storage paths, emails or user ids.
 */
final class CommandCommunicationPresenter
{
    /** @return array<string, mixed> */
    public function post(CommandCommunicationPost $post, User $viewer, bool $withReplies = true, ?string $parentId = null): array
    {
        $data = [
            'id' => $post->public_id,
            'parent_id' => $post->parent_id === null ? null : ($parentId ?? $this->parentPublicId($post)),
            'subject' => $post->subject,
            'body' => $post->body,
            'author' => [
                'name' => (string) $post->author->name,
                'rank' => $post->author->employeeProfile?->rank,
                'side' => $post->author_context,
            ],
            'is_mine' => (int) $post->author_user_id === (int) $viewer->getKey(),
            'created_at' => $post->created_at->toIso8601String(),
            'pinned' => $post->isPinned(),
            'attachments' => $post->attachments->map(fn (CommandCommunicationAttachment $attachment): array => $this->attachment($attachment))->all(),
        ];
        if ($withReplies) {
            $data['replies'] = $post->relationLoaded('replies')
                ? $post->replies->map(fn (CommandCommunicationPost $reply): array => $this->post($reply, $viewer, false, $post->public_id))->all()
                : [];
        }

        return $data;
    }

    /**
     * @param  iterable<CommandCommunicationPost>  $posts
     * @return list<array<string, mixed>>
     */
    public function posts(iterable $posts, User $viewer): array
    {
        $out = [];
        foreach ($posts as $post) {
            $out[] = $this->post($post, $viewer);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function attachment(CommandCommunicationAttachment $attachment): array
    {
        return [
            'id' => $attachment->public_id,
            'name' => $attachment->original_filename,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->byte_size,
            'kind' => $attachment->kind,
            'url' => route('command-communications.attachment', ['public_id' => $attachment->public_id], false),
        ];
    }

    private function parentPublicId(CommandCommunicationPost $post): ?string
    {
        if ($post->relationLoaded('parent')) {
            return $post->parent?->public_id;
        }

        return CommandCommunicationPost::query()->whereKey($post->parent_id)->value('public_id');
    }
}
