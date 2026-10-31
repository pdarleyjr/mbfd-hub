<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Mail\MailLabel;
use App\Models\Mail\MailThread;
use App\Models\Mail\MailThreadEvent;
use App\Models\Mail\MailThreadNote;
use App\Models\Mail\MailThreadUserState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything a member can do to a thread without sending mail. Authorization is checked on every call, so a
 * direct Livewire or HTTP request cannot do what the page hides.
 *
 *  - Personal state (read, unread, star) needs view access and never affects anyone else.
 *  - Shared state (archive, assign, priority, snooze, labels, notes) needs send access (which includes view),
 *    because it changes what colleagues see.
 *  - Trash and restore need manage access (admin.email.manage).
 *  - Automated threads (roster mail, system notifications) refuse every action.
 *  - A trashed thread refuses everything except restore (and personal state, so it can still be read).
 *
 * No action creates an outbound_emails row, touches inbound_emails, or sets a parent key.
 */
final class MailboxActions
{
    private const MAX_LABELS_PER_THREAD = 12;

    private const MAX_NOTE_LENGTH = 10000;

    public function __construct(private readonly MailAccess $access) {}

    public function markRead(User $user, MailThread $thread): void
    {
        $this->personal($user, $thread, function (MailThreadUserState $state, MailThread $fresh): void {
            $upTo = $fresh->last_inbound_at !== null && $fresh->last_inbound_at->gt(now()) ? $fresh->last_inbound_at : CarbonImmutable::now();
            $state->forceFill(['read_through_at' => $upTo, 'marked_unread_at' => null])->save();
        });
    }

    public function markUnread(User $user, MailThread $thread): void
    {
        $this->personal($user, $thread, function (MailThreadUserState $state): void {
            $state->forceFill(['marked_unread_at' => CarbonImmutable::now()])->save();
        });
    }

    public function star(User $user, MailThread $thread): void
    {
        $this->personal($user, $thread, function (MailThreadUserState $state): void {
            if ($state->starred_at === null) {
                $state->forceFill(['starred_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    public function unstar(User $user, MailThread $thread): void
    {
        $this->personal($user, $thread, function (MailThreadUserState $state): void {
            if ($state->starred_at !== null) {
                $state->forceFill(['starred_at' => null])->save();
            }
        });
    }

    public function archive(User $user, MailThread $thread): void
    {
        $this->shared($user, $thread, 'archived', fn (MailThread $fresh) => $fresh->archived_at === null
            ? ['archived_at' => CarbonImmutable::now(), 'snoozed_until' => null] : null);
    }

    public function unarchive(User $user, MailThread $thread): void
    {
        $this->shared($user, $thread, 'unarchived', fn (MailThread $fresh) => $fresh->archived_at !== null ? ['archived_at' => null] : null);
    }

    public function trash(User $user, MailThread $thread): void
    {
        $this->shared($user, $thread, 'trashed', fn (MailThread $fresh) => $fresh->trashed_at === null ? ['trashed_at' => CarbonImmutable::now()] : null, manage: true);
    }

    public function restore(User $user, MailThread $thread): void
    {
        $this->shared($user, $thread, 'restored', fn (MailThread $fresh) => $fresh->trashed_at !== null ? ['trashed_at' => null] : null, allowTrashed: true, manage: true);
    }

    public function assign(User $user, MailThread $thread, ?User $assignee): void
    {
        $this->authorizeShared($user); // before anything about the assignee is revealed
        if ($assignee !== null && (! $this->access->canView($assignee) || ! $this->access->canSend($assignee))) {
            throw ValidationException::withMessages(['assignee' => 'That person cannot use the Email Center, so they cannot be assigned a conversation.']);
        }
        $this->shared(
            $user, $thread, $assignee === null ? 'unassigned' : 'assigned',
            fn (MailThread $fresh) => (int) $fresh->assigned_user_id === (int) $assignee?->getKey() ? null : ['assigned_user_id' => $assignee?->getKey()],
            ['assignee_user_id' => $assignee?->getKey()],
        );
    }

    public function setPriority(User $user, MailThread $thread, string $priority): void
    {
        $this->authorizeShared($user);
        if (! in_array($priority, [MailThread::PRIORITY_NORMAL, MailThread::PRIORITY_HIGH], true)) {
            throw ValidationException::withMessages(['priority' => 'Priority must be normal or high.']);
        }
        $this->shared($user, $thread, 'priority_changed', fn (MailThread $fresh) => $fresh->priority === $priority ? null : ['priority' => $priority], ['priority' => $priority]);
    }

    /** @param CarbonInterface|null $until null wakes the conversation now */
    public function snooze(User $user, MailThread $thread, ?CarbonInterface $until): void
    {
        $this->authorizeShared($user);
        if ($until !== null && ($until->lte(now()) || $until->gt(now()->addYear()))) {
            throw ValidationException::withMessages(['until' => 'Choose a time in the future, within the next year.']);
        }
        $this->shared(
            $user, $thread, $until === null ? 'unsnoozed' : 'snoozed',
            fn (MailThread $fresh) => $until === null && $fresh->snoozed_until === null ? null : ['snoozed_until' => $until === null ? null : CarbonImmutable::instance($until)],
            $until === null ? [] : ['until' => CarbonImmutable::instance($until)->toIso8601String()],
        );
    }

    public function addLabel(User $user, MailThread $thread, string $name): void
    {
        $this->authorizeShared($user);
        $name = $this->labelName($name);
        $this->shared($user, $thread, 'label_added', function (MailThread $fresh) use ($name, $user): ?array {
            $label = $this->findOrCreateLabel($name, $user);
            if (DB::table('mail_thread_labels')->where('thread_id', $fresh->getKey())->where('label_id', $label->getKey())->exists()) {
                return null;
            }
            if (DB::table('mail_thread_labels')->where('thread_id', $fresh->getKey())->count() >= self::MAX_LABELS_PER_THREAD) {
                throw ValidationException::withMessages(['label' => 'A conversation can have at most '.self::MAX_LABELS_PER_THREAD.' labels.']);
            }
            DB::table('mail_thread_labels')->insertOrIgnore([
                'thread_id' => $fresh->getKey(), 'label_id' => $label->getKey(), 'created_by_user_id' => $user->getKey(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [];
        }, ['label' => $name]);
    }

    public function removeLabel(User $user, MailThread $thread, string $name): void
    {
        $this->authorizeShared($user);
        $name = $this->labelName($name);
        $this->shared($user, $thread, 'label_removed', function (MailThread $fresh) use ($name): ?array {
            $label = MailLabel::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
            if ($label === null) {
                return null;
            }

            return DB::table('mail_thread_labels')->where('thread_id', $fresh->getKey())->where('label_id', $label->getKey())->delete() > 0 ? [] : null;
        }, ['label' => $name]);
    }

    public function addNote(User $user, MailThread $thread, string $body): MailThreadNote
    {
        $this->authorizeShared($user);
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_NOTE_LENGTH) {
            throw ValidationException::withMessages(['note' => 'A note must be between 1 and '.self::MAX_NOTE_LENGTH.' characters.']);
        }
        $note = null;
        $this->shared($user, $thread, 'note_added', function (MailThread $fresh) use ($user, $body, &$note): array {
            $note = MailThreadNote::query()->create(['thread_id' => $fresh->getKey(), 'user_id' => $user->getKey(), 'body' => $body]);

            return [];
        }, null, false);

        return $note;
    }

    // ---------------------------------------------------------------------------------------------

    /** @param callable(MailThreadUserState, MailThread): void $change */
    private function personal(User $user, MailThread $thread, callable $change): void
    {
        if (! $this->access->canView($user)) {
            throw MailActionRefused::forbidden();
        }
        DB::transaction(function () use ($user, $thread, $change): void {
            $fresh = MailThread::query()->lockForUpdate()->findOrFail($thread->getKey());
            if ($fresh->isAutomated()) {
                throw MailActionRefused::automatedThread();
            }
            $change($this->state($user, $fresh), $fresh);
        });
        $thread->refresh();
    }

    /**
     * @param  callable(MailThread): (array<string, mixed>|null)  $change  column changes, [] for "done elsewhere", or null for "nothing to do"
     * @param  array<string, mixed>|null  $metadata  event metadata; null records no event
     */
    private function shared(User $user, MailThread $thread, string $event, callable $change, ?array $metadata = [], bool $allowTrashed = false, bool $manage = false): void
    {
        $this->authorizeShared($user, $manage);
        DB::transaction(function () use ($user, $thread, $event, $change, $metadata, $allowTrashed): void {
            $fresh = MailThread::query()->lockForUpdate()->findOrFail($thread->getKey());
            if ($fresh->isAutomated()) {
                throw MailActionRefused::automatedThread();
            }
            if ($fresh->isTrashed() && ! $allowTrashed) {
                throw MailActionRefused::trashedThread();
            }
            $columns = $change($fresh);
            if ($columns === null) {
                return;
            }
            if ($columns !== []) {
                $fresh->forceFill($columns)->save();
            }
            MailThreadEvent::query()->create([
                'thread_id' => $fresh->getKey(), 'user_id' => $user->getKey(), 'type' => $event,
                'metadata' => $metadata === [] || $metadata === null ? null : $metadata,
            ]);
        });
        $thread->refresh();
    }

    /** Shared state needs send access; trash and restore need manage access (which includes view). */
    private function authorizeShared(User $user, bool $manage = false): void
    {
        if (! ($manage ? $this->access->canManage($user) : $this->access->canSend($user))) {
            throw MailActionRefused::forbidden($manage ? 'You do not have permission to manage the shared mailbox.' : 'You do not have permission to change the shared mailbox.');
        }
    }

    private function state(User $user, MailThread $thread): MailThreadUserState
    {
        DB::table('mail_thread_user_states')->insertOrIgnore([
            'user_id' => $user->getKey(), 'thread_id' => $thread->getKey(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return MailThreadUserState::query()->where('user_id', $user->getKey())->where('thread_id', $thread->getKey())->lockForUpdate()->firstOrFail();
    }

    private function labelName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 40 || preg_match('/[\x00-\x1F\x7F<>]/u', $name) === 1) {
            throw ValidationException::withMessages(['label' => 'A label is 1 to 40 characters and cannot contain control characters or angle brackets.']);
        }

        return $name;
    }

    private function findOrCreateLabel(string $name, User $user): MailLabel
    {
        $existing = MailLabel::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        if ($existing !== null) {
            return $existing;
        }
        // insertOrIgnore: a duplicate would abort a PostgreSQL transaction, and the caller is inside one.
        DB::table('mail_labels')->insertOrIgnore(['name' => $name, 'created_by_user_id' => $user->getKey(), 'created_at' => now(), 'updated_at' => now()]);

        return MailLabel::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->firstOrFail();
    }
}
