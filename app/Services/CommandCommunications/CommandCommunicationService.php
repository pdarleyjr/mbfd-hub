<?php

declare(strict_types=1);

namespace App\Services\CommandCommunications;

use App\Models\CommandCommunicationPost;
use App\Models\CommandCommunicationReadState;
use App\Models\User;
use App\Services\Security\Command300Access;
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
 * The single backend for the shared 300 Command conversation. Both surfaces
 * (the 300 page API and the Filament admin page) call this class, and every
 * public method re-checks the one 300 Command authorization decision.
 */
final class CommandCommunicationService
{
    private const CONTEXTS = [CommandCommunicationPost::CONTEXT_COMMAND, CommandCommunicationPost::CONTEXT_ADMINISTRATION];

    public function __construct(
        private readonly Command300Access $access,
        private readonly CommandCommunicationAttachmentStore $attachments,
    ) {}

    public function can(?User $user): bool
    {
        return $this->access->allows($user);
    }

    /**
     * @param  list<mixed>  $files  UploadedFile instances (including Livewire temporary uploads)
     */
    public function post(
        User $author,
        string $context,
        ?string $subject,
        string $body,
        ?string $parentPublicId,
        string $clientSubmissionId,
        array $files = [],
    ): CommandCommunicationPost {
        $this->authorize($author);
        if (! in_array($context, self::CONTEXTS, true)) {
            throw new \InvalidArgumentException('Unknown author context.');
        }
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
        $parent = $this->resolveParent($parentPublicId);

        $written = $this->attachments->write($inspected, $author->getKey());
        try {
            $post = DB::transaction(function () use ($author, $context, $subject, $body, $parent, $clientSubmissionId, $written): CommandCommunicationPost {
                $post = CommandCommunicationPost::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'parent_id' => $parent?->getKey(),
                    'author_user_id' => $author->getKey(),
                    'author_context' => $context,
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
            'attachment_count' => count($written),
        ]);

        return $this->hydrate($post);
    }

    /**
     * Newest-first top-level posts (replies oldest-first, attachments eager loaded).
     *
     * @return array{posts: Collection<int, CommandCommunicationPost>, pinned: Collection<int, CommandCommunicationPost>, next_before: string|null, unread_count: int}
     */
    public function feed(User $viewer, ?string $beforePublicId = null, ?int $limit = null): array
    {
        $this->authorize($viewer);
        $max = (int) config('command_communications.max_page_size', 50);

        return $this->page($viewer, $beforePublicId, max(1, min($limit ?? (int) config('command_communications.page_size', 20), $max)));
    }

    /**
     * The newest $count top-level posts, for the admin page's "load more".
     *
     * @return array{posts: Collection<int, CommandCommunicationPost>, pinned: Collection<int, CommandCommunicationPost>, next_before: string|null, unread_count: int}
     */
    public function newest(User $viewer, int $count): array
    {
        $this->authorize($viewer);

        return $this->page($viewer, null, max(1, min($count, 500)));
    }

    /** @return array{posts: Collection<int, CommandCommunicationPost>, pinned: Collection<int, CommandCommunicationPost>, next_before: string|null, unread_count: int} */
    private function page(User $viewer, ?string $beforePublicId, int $limit): array
    {
        $query = $this->topLevel()->orderByDesc('id');
        if ($beforePublicId !== null && $beforePublicId !== '') {
            $cursor = CommandCommunicationPost::query()->whereNull('parent_id')->where('public_id', $beforePublicId)->first();
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
            'pinned' => $this->pinned(),
            'next_before' => $hasMore ? $posts->last()?->public_id : null,
            'unread_count' => $this->unreadCount($viewer),
        ];
    }

    /** @return Collection<int, CommandCommunicationPost> */
    public function pinned(): Collection
    {
        return $this->topLevel()->whereNotNull('pinned_at')->orderByDesc('pinned_at')->orderByDesc('id')->limit(10)->get();
    }

    /**
     * Case-insensitive LIKE over subject, body and reply bodies. Bounded.
     *
     * @return Collection<int, CommandCommunicationPost>
     */
    public function search(User $viewer, string $term): Collection
    {
        $this->authorize($viewer);
        $term = trim(mb_substr($term, 0, 100));
        if (mb_strlen($term) < (int) config('command_communications.search_min_length', 2)) {
            return new Collection;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';

        return $this->topLevel()
            ->where(function (Builder $query) use ($like): void {
                $query->whereRaw("LOWER(COALESCE(subject, '')) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(body) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereHas('replies', fn (Builder $reply) => $reply->whereRaw("LOWER(body) LIKE ? ESCAPE '\\'", [$like]));
            })
            ->orderByDesc('id')
            ->limit((int) config('command_communications.search_limit', 50))
            ->get();
    }

    /** Posts and replies by someone else created after the viewer's last read. */
    public function unreadCount(User $viewer): int
    {
        if (! $this->access->allows($viewer)) {
            return 0;
        }
        $lastRead = $this->lastReadAt($viewer);
        $query = CommandCommunicationPost::query()->where('author_user_id', '!=', $viewer->getKey());
        if ($lastRead !== null) {
            $query->where('created_at', '>', $lastRead);
        }

        return $query->count();
    }

    public function lastReadAt(User $viewer): ?CarbonInterface
    {
        return CommandCommunicationReadState::query()->find($viewer->getKey())?->last_read_at;
    }

    public function latestActivityAt(): ?CarbonInterface
    {
        $latest = CommandCommunicationPost::query()->latest('id')->first(['created_at']);

        return $latest?->created_at;
    }

    public function markRead(User $viewer): CarbonInterface
    {
        $this->authorize($viewer);
        $now = now();
        try {
            CommandCommunicationReadState::query()->updateOrCreate(['user_id' => $viewer->getKey()], ['last_read_at' => $now]);
        } catch (UniqueConstraintViolationException) {
            CommandCommunicationReadState::query()->whereKey($viewer->getKey())->update(['last_read_at' => $now]);
        }

        return $now;
    }

    public function setPinned(User $actor, CommandCommunicationPost $post, bool $pinned): CommandCommunicationPost
    {
        $this->authorize($actor);
        if ($post->parent_id !== null) {
            throw ValidationException::withMessages(['pinned' => 'Only top-level posts can be pinned.']);
        }
        if ($pinned && ! $post->isPinned()) {
            $post->forceFill(['pinned_at' => now(), 'pinned_by_user_id' => $actor->getKey()])->save();
        } elseif (! $pinned && $post->isPinned()) {
            $post->forceFill(['pinned_at' => null, 'pinned_by_user_id' => null])->save();
        } else {
            return $this->hydrate($post);
        }

        Log::info('command_communications.pin_changed', [
            'post_id' => $post->public_id,
            'pinned' => $pinned,
            'actor_user_id' => $actor->getKey(),
        ]);

        return $this->hydrate($post);
    }

    public function findTopLevel(User $viewer, string $publicId): ?CommandCommunicationPost
    {
        $this->authorize($viewer);

        return $this->topLevel()->where('public_id', $publicId)->first();
    }

    /** @return Builder<CommandCommunicationPost> */
    private function topLevel(): Builder
    {
        return CommandCommunicationPost::query()->whereNull('parent_id')->with([
            'author.employeeProfile',
            'attachments',
            'replies.author.employeeProfile',
            'replies.attachments',
        ]);
    }

    private function hydrate(CommandCommunicationPost $post): CommandCommunicationPost
    {
        $post->load(['author.employeeProfile', 'attachments']);
        if ($post->parent_id === null) {
            $post->load(['replies.author.employeeProfile', 'replies.attachments']);
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

    private function resolveParent(?string $parentPublicId): ?CommandCommunicationPost
    {
        if ($parentPublicId === null || $parentPublicId === '') {
            return null;
        }
        $parent = CommandCommunicationPost::query()->where('public_id', $parentPublicId)->first();
        if (! $parent instanceof CommandCommunicationPost || $parent->parent_id !== null) {
            throw ValidationException::withMessages(['parent_id' => 'You can only reply to an existing top-level post.']);
        }

        return $parent;
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

    private function authorize(User $user): void
    {
        if (! $this->access->allows($user)) {
            throw new AuthorizationException('300 Command Communications is not available to this account.');
        }
    }
}
