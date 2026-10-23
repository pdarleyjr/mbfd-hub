<?php

declare(strict_types=1);

namespace App\Services\CommandCommunications;

use App\Models\CommandCommunicationAuditEvent;
use App\Models\CommandCommunicationPost;
use App\Models\User;
use App\Services\Captain5\Captain5Notifier;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The single backend for the shared 300 Command / Captain 5 conversation. The 300 page API, the
 * Captain 5 page API and the Filament admin page all call this class, and every public method
 * re-checks the one authorization decision in CommandCommunicationAccess.
 *
 * A post is one row with an `audience` and appears in every feed whose channel it includes. A
 * "scope" argument names a channel (`command_300`, `captain_5`) or, for administrators, `all`.
 * It defaults to the 300 channel so existing 300 callers are unchanged.
 */
final class CommandCommunicationService
{
    private const CONTEXTS = [
        CommandCommunicationPost::CONTEXT_COMMAND,
        CommandCommunicationPost::CONTEXT_ADMINISTRATION,
        CommandCommunicationPost::CONTEXT_CAPTAIN5,
    ];

    private const MIN_REASON_LENGTH = 3;

    private const MAX_REASON_LENGTH = 500;

    public function __construct(
        private readonly CommandCommunicationAccess $access,
        private readonly CommandCommunicationAttachmentStore $attachments,
        private readonly CommandCommunicationReadTracker $reads,
    ) {}

    /** Can this user read the 300 channel (the original meaning, kept for 300 callers). */
    public function can(?User $user): bool
    {
        return $this->access->canRead($user, CommandCommunicationPost::CHANNEL_COMMAND_300);
    }

    public function access(): CommandCommunicationAccess
    {
        return $this->access;
    }

    /**
     * @param  list<mixed>  $files  UploadedFile instances (including Livewire temporary uploads)
     * @param  string|null  $audience  administrators only; every other author's audience is fixed by their channel
     */
    public function post(
        User $author,
        string $context,
        ?string $subject,
        string $body,
        ?string $parentPublicId,
        string $clientSubmissionId,
        array $files = [],
        ?string $audience = null,
    ): CommandCommunicationPost {
        if (! in_array($context, self::CONTEXTS, true)) {
            throw new \InvalidArgumentException('Unknown author context.');
        }
        $channels = $this->writeChannels($author, $context);
        if (! preg_match('/^[A-Za-z0-9-]{8,64}$/D', $clientSubmissionId)) {
            throw ValidationException::withMessages(['client_submission_id' => 'A valid submission id (8-64 letters, digits or hyphens) is required.']);
        }

        $existing = $this->existing($author, $clientSubmissionId);
        if ($existing instanceof CommandCommunicationPost) {
            return $existing;
        }

        [$subject, $body] = $this->cleanText($subject, $body);
        $files = array_values(array_filter($files, static fn (mixed $file): bool => $file !== null));
        $inspected = $this->attachments->inspect($files);
        if ($body === '' && $inspected === []) {
            throw ValidationException::withMessages(['body' => 'Write a message or attach a file.']);
        }
        $parent = $this->resolveParent($channels, $parentPublicId);
        $audience = $parent->audience ?? $this->newAudience($channels, $context, $audience);

        $written = $this->attachments->write($inspected, $author->getKey());
        try {
            $post = DB::transaction(function () use ($author, $context, $audience, $subject, $body, $parent, $clientSubmissionId, $written): CommandCommunicationPost {
                $post = CommandCommunicationPost::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'parent_id' => $parent?->getKey(),
                    'author_user_id' => $author->getKey(),
                    'author_context' => $context,
                    'audience' => $audience,
                    'subject' => $parent === null ? $subject : null,
                    'body' => $body,
                    'client_submission_id' => $clientSubmissionId,
                ]);
                foreach ($written as $row) {
                    $post->attachments()->create($row);
                }

                return $post;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->attachments->delete($written);
            $winner = $this->existing($author, $clientSubmissionId);
            if ($winner instanceof CommandCommunicationPost) {
                return $winner;
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $this->attachments->delete($written);
            throw $exception;
        }

        Log::info('command_communications.post_created', [
            'post_id' => $post->public_id,
            'parent_id' => $parent?->public_id,
            'author_user_id' => $author->getKey(),
            'context' => $context,
            'audience' => $audience,
            'attachment_count' => count($written),
        ]);
        $this->notifyCaptain5($post, $context);

        return $this->hydrate($post);
    }

    /**
     * Newest-first top-level posts (replies oldest-first, attachments eager loaded).
     *
     * @return array{posts: Collection<int, CommandCommunicationPost>, pinned: Collection<int, CommandCommunicationPost>, next_before: string|null, unread_count: int}
     */
    public function feed(User $viewer, ?string $beforePublicId = null, ?int $limit = null, string $scope = CommandCommunicationPost::CHANNEL_COMMAND_300): array
    {
        $max = (int) config('command_communications.max_page_size', 50);

        return $this->page($viewer, $scope, $beforePublicId, max(1, min($limit ?? (int) config('command_communications.page_size', 20), $max)));
    }

    /**
     * The newest $count top-level posts, for the admin page's "load more".
     *
     * @return array{posts: Collection<int, CommandCommunicationPost>, pinned: Collection<int, CommandCommunicationPost>, next_before: string|null, unread_count: int}
     */
    public function newest(User $viewer, int $count, string $scope = CommandCommunicationPost::CHANNEL_COMMAND_300): array
    {
        return $this->page($viewer, $scope, null, max(1, min($count, 500)));
    }

    /** @return array{posts: Collection<int, CommandCommunicationPost>, pinned: Collection<int, CommandCommunicationPost>, next_before: string|null, unread_count: int} */
    private function page(User $viewer, string $scope, ?string $beforePublicId, int $limit): array
    {
        [$channels, $audiences] = $this->resolve($viewer, $scope);
        $query = $this->topLevel($audiences)->orderByDesc('id');
        if ($beforePublicId !== null && $beforePublicId !== '') {
            $cursor = CommandCommunicationPost::query()->whereNull('parent_id')
                ->whereIn('audience', $audiences)->where('public_id', $beforePublicId)->first();
            if (! $cursor instanceof CommandCommunicationPost) {
                throw ValidationException::withMessages(['before' => 'That position in the conversation no longer exists.']);
            }
            $query->where('id', '<', $cursor->getKey());
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $posts = $rows->take($limit)->values();

        return [
            'posts' => $posts,
            'pinned' => $this->pinnedIn($audiences),
            'next_before' => $hasMore ? $posts->last()?->public_id : null,
            'unread_count' => $this->reads->unreadCount($viewer, $channels),
        ];
    }

    /** @return Collection<int, CommandCommunicationPost> */
    public function pinned(User $viewer, string $scope = CommandCommunicationPost::CHANNEL_COMMAND_300): Collection
    {
        return $this->pinnedIn($this->resolve($viewer, $scope)[1]);
    }

    /**
     * @param  list<string>  $audiences
     * @return Collection<int, CommandCommunicationPost>
     */
    private function pinnedIn(array $audiences): Collection
    {
        return $this->topLevel($audiences)->whereNotNull('pinned_at')->whereNull('deleted_at')
            ->orderByDesc('pinned_at')->orderByDesc('id')->limit(10)->get();
    }

    /**
     * Case-insensitive LIKE over subject, body and reply bodies. Bounded; removed text is never searched.
     *
     * @return Collection<int, CommandCommunicationPost>
     */
    public function search(User $viewer, string $term, string $scope = CommandCommunicationPost::CHANNEL_COMMAND_300): Collection
    {
        $audiences = $this->resolve($viewer, $scope)[1];
        $term = trim(mb_substr($term, 0, 100));
        if (mb_strlen($term) < (int) config('command_communications.search_min_length', 2)) {
            return new Collection;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';

        return $this->topLevel($audiences)
            ->where(function (Builder $query) use ($like): void {
                $query->where(function (Builder $own) use ($like): void {
                    $own->whereNull('deleted_at')->where(function (Builder $text) use ($like): void {
                        $text->whereRaw("LOWER(COALESCE(subject, '')) LIKE ? ESCAPE '\\'", [$like])
                            ->orWhereRaw("LOWER(body) LIKE ? ESCAPE '\\'", [$like]);
                    });
                })->orWhereHas('replies', fn (Builder $reply) => $reply->whereNull('deleted_at')->whereRaw("LOWER(body) LIKE ? ESCAPE '\\'", [$like]));
            })
            ->orderByDesc('id')
            ->limit((int) config('command_communications.search_limit', 50))
            ->get();
    }

    /** Posts and replies by someone else created after the viewer's last read, in this scope. */
    public function unreadCount(User $viewer, string $scope = CommandCommunicationPost::CHANNEL_COMMAND_300): int
    {
        return $this->reads->unreadCount($viewer, $this->access->channelsFor($viewer, $scope));
    }

    /** The viewer's marker for one channel (never the combined scope). */
    public function lastReadAt(User $viewer, string $channel = CommandCommunicationPost::CHANNEL_COMMAND_300): ?CarbonInterface
    {
        return $this->reads->lastReadAt($viewer, $channel);
    }

    /**
     * Markers for every channel the scope covers, for the admin page's "New" tags.
     *
     * @return array<string, CarbonInterface|null>
     */
    public function lastReadByChannel(User $viewer, string $scope): array
    {
        return $this->reads->lastReadByChannel($viewer, $this->access->channelsFor($viewer, $scope));
    }

    public function latestActivityAt(?User $viewer = null, string $scope = CommandCommunicationPost::CHANNEL_COMMAND_300): ?CarbonInterface
    {
        $audiences = $viewer instanceof User
            ? $this->access->audiencesFor($viewer, $scope)
            : CommandCommunicationPost::audiencesFor($scope);

        return CommandCommunicationPost::query()->whereIn('audience', $audiences)->whereNull('deleted_at')
            ->latest('id')->first(['created_at'])?->created_at;
    }

    public function markRead(User $viewer, string $scope = CommandCommunicationPost::CHANNEL_COMMAND_300): CarbonInterface
    {
        return $this->reads->markRead($viewer, $this->resolve($viewer, $scope)[0]);
    }

    public function setPinned(User $actor, CommandCommunicationPost $post, bool $pinned, string $scope = CommandCommunicationPost::CHANNEL_COMMAND_300): CommandCommunicationPost
    {
        $audiences = $this->resolve($actor, $scope)[1];
        if (! $this->access->canPin($actor, $scope)) {
            throw new AuthorizationException('You cannot pin or unpin messages in this conversation.');
        }
        if (! in_array($post->audience, $audiences, true)) {
            throw new AuthorizationException('That message is not part of this conversation.');
        }
        if ($post->parent_id !== null) {
            throw ValidationException::withMessages(['pinned' => 'Only top-level posts can be pinned.']);
        }
        if ($post->isDeleted()) {
            throw ValidationException::withMessages(['pinned' => 'That message was removed.']);
        }
        if ($pinned === $post->isPinned()) {
            return $this->hydrate($post);
        }

        DB::transaction(function () use ($actor, $post, $pinned): void {
            $post->forceFill($pinned
                ? ['pinned_at' => now(), 'pinned_by_user_id' => $actor->getKey()]
                : ['pinned_at' => null, 'pinned_by_user_id' => null])->save();
            $this->audit($post, $actor, $pinned ? CommandCommunicationAuditEvent::ACTION_PINNED : CommandCommunicationAuditEvent::ACTION_UNPINNED, null);
        });

        Log::info('command_communications.pin_changed', [
            'post_id' => $post->public_id,
            'pinned' => $pinned,
            'actor_user_id' => $actor->getKey(),
        ]);

        return $this->hydrate($post);
    }

    /**
     * Controlled removal: the author, or a communications administrator, with a required reason.
     * The post stays as a tombstone so replies keep their thread; its body and attachments are
     * no longer served. The audit event is written in the same transaction.
     */
    public function delete(User $actor, CommandCommunicationPost $post, string $reason, string $scope = CommandCommunicationPost::CHANNEL_COMMAND_300): CommandCommunicationPost
    {
        $audiences = $this->resolve($actor, $scope)[1];
        if (! in_array($post->audience, $audiences, true)) {
            throw new AuthorizationException('That message is not part of this conversation.');
        }
        if (! $this->access->canDelete($actor, $post) && ! $post->isDeleted()) {
            throw new AuthorizationException('You can remove only your own messages.');
        }
        $reason = trim((string) preg_replace('/\s+/u', ' ', str_replace("\0", '', $reason)));
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw ValidationException::withMessages(['reason' => 'Say why this message is being removed.']);
        }
        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw ValidationException::withMessages(['reason' => 'Keep the reason under '.self::MAX_REASON_LENGTH.' characters.']);
        }

        DB::transaction(function () use ($actor, $post, $reason): void {
            $locked = CommandCommunicationPost::query()->lockForUpdate()->findOrFail($post->getKey());
            if ($locked->isDeleted()) {
                throw ValidationException::withMessages(['post' => 'That message was already removed.']);
            }
            $locked->forceFill([
                'deleted_at' => now(),
                'deleted_by_user_id' => $actor->getKey(),
                'deletion_reason' => $reason,
            ])->save();
            $this->audit($locked, $actor, CommandCommunicationAuditEvent::ACTION_DELETED, $reason);
        });

        Log::info('command_communications.post_removed', [
            'post_id' => $post->public_id,
            'actor_user_id' => $actor->getKey(),
            'by_author' => (int) $post->author_user_id === (int) $actor->getKey(),
        ]);

        return $this->hydrate($post->refresh());
    }

    public function findTopLevel(User $viewer, string $publicId, string $scope = CommandCommunicationPost::CHANNEL_COMMAND_300): ?CommandCommunicationPost
    {
        return $this->topLevel($this->resolve($viewer, $scope)[1])->where('public_id', $publicId)->first();
    }

    /** A top-level post or reply visible in the scope (for removal). */
    public function find(User $viewer, string $publicId, string $scope = CommandCommunicationPost::CHANNEL_COMMAND_300): ?CommandCommunicationPost
    {
        return CommandCommunicationPost::query()->with(['author.employeeProfile', 'deletedBy'])
            ->whereIn('audience', $this->resolve($viewer, $scope)[1])->where('public_id', $publicId)->first();
    }

    /**
     * @param  list<string>  $audiences
     * @return Builder<CommandCommunicationPost>
     */
    private function topLevel(array $audiences): Builder
    {
        return CommandCommunicationPost::query()->whereNull('parent_id')
            ->whereIn('audience', $audiences)
            ->with([
                'author.employeeProfile',
                'deletedBy',
                'attachments',
                'replies.author.employeeProfile',
                'replies.deletedBy',
                'replies.attachments',
            ]);
    }

    private function hydrate(CommandCommunicationPost $post): CommandCommunicationPost
    {
        $post->load(['author.employeeProfile', 'deletedBy', 'attachments']);
        if ($post->parent_id === null) {
            $post->load(['replies.author.employeeProfile', 'replies.deletedBy', 'replies.attachments']);
        } else {
            $post->setRelation('replies', new Collection);
        }

        return $post;
    }

    private function existing(User $author, string $clientSubmissionId): ?CommandCommunicationPost
    {
        $post = CommandCommunicationPost::query()
            ->where('author_user_id', $author->getKey())
            ->where('client_submission_id', $clientSubmissionId)
            ->first();

        return $post instanceof CommandCommunicationPost ? $this->hydrate($post) : null;
    }

    /**
     * Replies are visible exactly where their parent is, so the parent must be visible in the
     * author's scope and still present.
     */
    private function resolveParent(array $channels, ?string $parentPublicId): ?CommandCommunicationPost
    {
        if ($parentPublicId === null || $parentPublicId === '') {
            return null;
        }
        $parent = CommandCommunicationPost::query()
            ->whereIn('audience', CommandCommunicationAccess::audiencesForChannels($channels))
            ->where('public_id', $parentPublicId)->first();
        if (! $parent instanceof CommandCommunicationPost || $parent->parent_id !== null) {
            throw ValidationException::withMessages(['parent_id' => 'You can only reply to an existing top-level post.']);
        }
        if ($parent->isDeleted()) {
            throw ValidationException::withMessages(['parent_id' => 'That message was removed, so it cannot be replied to.']);
        }

        return $parent;
    }

    /**
     * 300 members write in the 300 channel, Captain 5 members in the Captain 5 channel, and
     * administrators across every channel they can read. Returns the channels the author writes in.
     *
     * @return list<string>
     */
    private function writeChannels(User $author, string $context): array
    {
        $scope = match ($context) {
            CommandCommunicationPost::CONTEXT_COMMAND => CommandCommunicationPost::CHANNEL_COMMAND_300,
            CommandCommunicationPost::CONTEXT_CAPTAIN5 => CommandCommunicationPost::CHANNEL_CAPTAIN_5,
            default => CommandCommunicationAccess::SCOPE_ALL,
        };

        return $this->resolve($author, $scope)[0];
    }

    /** The audience of a new top-level post. */
    private function newAudience(array $channels, string $context, ?string $requested): string
    {
        $fixed = match ($context) {
            CommandCommunicationPost::CONTEXT_COMMAND => CommandCommunicationPost::AUDIENCE_COMMAND_300,
            CommandCommunicationPost::CONTEXT_CAPTAIN5 => CommandCommunicationPost::AUDIENCE_CAPTAIN_5,
            default => null,
        };
        if ($fixed !== null) {
            if ($requested !== null && $requested !== $fixed) {
                throw new \InvalidArgumentException('Only administrators choose an audience.');
            }

            return $fixed;
        }

        $audience = $requested ?? CommandCommunicationPost::AUDIENCE_COMMAND_300;
        if (! in_array($audience, CommandCommunicationPost::AUDIENCES, true)) {
            throw ValidationException::withMessages(['audience' => 'Choose who should see this message.']);
        }
        $missing = array_diff(CommandCommunicationPost::channelsFor($audience), $channels);
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'audience' => in_array(CommandCommunicationPost::CHANNEL_CAPTAIN_5, $missing, true) && ! $this->access->captain5Enabled()
                    ? 'Captain 5 messages are turned off right now.'
                    : 'You cannot send messages to that audience.',
            ]);
        }

        return $audience;
    }

    /**
     * An administrator wrote to the Captain 5 channel: only the on-duty Captain 5 is told
     * immediately (everyone else eligible sees the post and the unread count). Never throws.
     */
    private function notifyCaptain5(CommandCommunicationPost $post, string $context): void
    {
        if ($context !== CommandCommunicationPost::CONTEXT_ADMINISTRATION
            || ! in_array(CommandCommunicationPost::CHANNEL_CAPTAIN_5, CommandCommunicationPost::channelsFor($post->audience), true)) {
            return;
        }
        try {
            app(Captain5Notifier::class)->communicationPosted([
                'public_id' => $post->public_id,
                'subject' => $post->subject,
                'body' => $post->body,
                'author_user_id' => (int) $post->author_user_id,
                'audience' => $post->audience,
            ]);
        } catch (\Throwable $exception) {
            Log::error('command_communications.captain5_notification_failed', ['post_id' => $post->public_id, 'exception' => $exception->getMessage()]);
        }
    }

    private function audit(CommandCommunicationPost $post, User $actor, string $action, ?string $reason): void
    {
        CommandCommunicationAuditEvent::query()->create([
            'post_id' => $post->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => $action,
            'reason' => $reason,
        ]);
    }

    /** @return array{0: string|null, 1: string} */
    private function cleanText(?string $subject, string $body): array
    {
        $subject = $subject === null ? null : trim((string) preg_replace('/\s+/u', ' ', str_replace("\0", '', $subject)));
        $subject = $subject === '' ? null : $subject;
        $body = trim(str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $body));
        $bodyMax = (int) config('command_communications.body_max_length', 10000);
        $subjectMax = (int) config('command_communications.subject_max_length', 160);
        if (mb_strlen($body) > $bodyMax) {
            throw ValidationException::withMessages(['body' => "Keep the message under {$bodyMax} characters."]);
        }
        if ($subject !== null && mb_strlen($subject) > $subjectMax) {
            throw ValidationException::withMessages(['subject' => "Keep the subject under {$subjectMax} characters."]);
        }

        return [$subject, $body];
    }

    public function assertCanRead(User $user, string $scope): void
    {
        $this->resolve($user, $scope);
    }

    /**
     * One authorization evaluation per operation: the channels and audiences a scope covers for
     * this user. Throws when the user may not read the scope.
     *
     * @return array{0: list<string>, 1: list<string>} [channels, audiences]
     */
    private function resolve(User $user, string $scope): array
    {
        $channels = $this->access->channelsFor($user, $scope);
        if ($channels === []) {
            throw new AuthorizationException($scope === CommandCommunicationPost::CHANNEL_CAPTAIN_5
                ? 'Captain 5 Communications is not available to this account.'
                : '300 Command Communications is not available to this account.');
        }

        return [$channels, CommandCommunicationAccess::audiencesForChannels($channels)];
    }
}
