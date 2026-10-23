<?php

declare(strict_types=1);

namespace App\Services\CommandCommunications;

use App\Models\CommandCommunicationAttachment;
use App\Models\CommandCommunicationPost;
use App\Models\User;

/**
 * Turns posts into the public JSON shape of the 300 Command and Captain 5 conversation APIs.
 * Exposes only display data: never storage paths, emails or user ids. A removed post is a
 * tombstone: no subject, body or attachments, only who removed it and when (never the reason).
 */
final class CommandCommunicationPresenter
{
    public function __construct(private readonly CommandCommunicationAccess $access) {}

    /** @return array<string, mixed> */
    public function post(CommandCommunicationPost $post, User $viewer, bool $withReplies = true, ?string $parentId = null): array
    {
        $moderator = null;

        return $this->build($post, $viewer, $withReplies, $parentId, $moderator);
    }

    /**
     * @param  bool|null  $moderator  the viewer's moderator decision, computed at most once per presentation
     * @return array<string, mixed>
     */
    private function build(CommandCommunicationPost $post, User $viewer, bool $withReplies, ?string $parentId, ?bool &$moderator): array
    {
        $deleted = $post->isDeleted();
        $data = [
            'id' => $post->public_id,
            'parent_id' => $post->parent_id === null ? null : ($parentId ?? $this->parentPublicId($post)),
            'audience' => $post->audience,
            'subject' => $deleted ? null : $post->subject,
            'body' => $deleted ? '' : $post->body,
            'author' => [
                'name' => (string) $post->author->name,
                'rank' => $post->author->employeeProfile?->rank,
                'side' => $post->author_context,
            ],
            'is_mine' => (int) $post->author_user_id === (int) $viewer->getKey(),
            'created_at' => $post->created_at->toIso8601String(),
            'pinned' => ! $deleted && $post->isPinned(),
            'deleted' => $deleted,
            'removed' => $deleted ? [
                'by' => (string) ($post->deletedBy->name ?? 'an administrator'),
                'at' => $post->deleted_at?->toIso8601String(),
            ] : null,
            'can_delete' => ! $deleted && ((int) $post->author_user_id === (int) $viewer->getKey() || ($moderator ??= $this->access->canModerate($viewer))),
            'attachments' => $deleted ? [] : $post->attachments->map(fn (CommandCommunicationAttachment $attachment): array => $this->attachment($attachment))->all(),
        ];
        if ($withReplies) {
            $data['replies'] = $post->relationLoaded('replies')
                ? $post->replies->map(function (CommandCommunicationPost $reply) use ($viewer, $post, &$moderator): array {
                    return $this->build($reply, $viewer, false, $post->public_id, $moderator);
                })->all()
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
        $moderator = null;
        foreach ($posts as $post) {
            $out[] = $this->build($post, $viewer, true, null, $moderator);
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
