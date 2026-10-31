<?php

declare(strict_types=1);

namespace App\Livewire\Mail;

use App\Enums\AccountStatus;
use App\Models\Mail\MailLabel;
use App\Models\Mail\MailThread;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailActionRefused;
use App\Services\Mail\MailboxActions;
use App\Services\Mail\MailboxQuery;
use App\Services\Mail\MailThreadDetail;
use App\Support\HubTimezone;
use App\Support\Mail\MailPresentation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * The right column: one whole conversation, oldest message first, with the actions a member can take on it.
 * It never trusts the browser for anything but the conversation id, and every action goes through
 * MailboxActions, which checks the member's access again. The page rebuilds this component whenever the open
 * conversation changes, so mount() is the moment a conversation counts as read for the member looking at it.
 */
final class MessageReader extends Component
{
    #[Locked]
    public int $threadId = 0;

    public string $noteBody = '';

    public string $labelName = '';

    public string $snoozeChoice = 'tomorrow';

    public ?string $error = null;

    public ?string $notice = null;

    public function mount(int $threadId): void
    {
        $user = $this->authorizeAccess();
        $this->threadId = $threadId;
        try {
            $thread = MailThread::query()->find($threadId);
            if ($thread !== null && ! $thread->isAutomated()) {
                app(MailboxActions::class)->markRead($user, $thread);
                $this->dispatch('mail-unread-changed');
            }
        } catch (MailActionRefused) {
            // Read state is a convenience; the conversation is still shown.
        }
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    // ---------------------------------------------------------------------------------------------
    // Personal state
    // ---------------------------------------------------------------------------------------------

    public function toggleStar(): void
    {
        $this->act(function (MailboxActions $actions, User $user, MailThread $thread): string {
            $starred = $thread->userStates()->where('user_id', $user->getKey())->whereNotNull('starred_at')->exists();
            $starred ? $actions->unstar($user, $thread) : $actions->star($user, $thread);

            return $starred ? 'Star removed.' : 'Starred.';
        });
    }

    public function markUnread(): void
    {
        $this->act(function (MailboxActions $actions, User $user, MailThread $thread): string {
            $actions->markUnread($user, $thread);

            return 'Marked as unread.';
        });
    }

    // ---------------------------------------------------------------------------------------------
    // Shared state
    // ---------------------------------------------------------------------------------------------

    public function archive(): void
    {
        $this->act(function (MailboxActions $actions, User $user, MailThread $thread): string {
            $actions->archive($user, $thread);

            return 'Archived. It is in the Archive folder.';
        });
    }

    public function unarchive(): void
    {
        $this->act(function (MailboxActions $actions, User $user, MailThread $thread): string {
            $actions->unarchive($user, $thread);

            return 'Moved back to the Inbox.';
        });
    }

    public function trash(): void
    {
        $this->act(function (MailboxActions $actions, User $user, MailThread $thread): string {
            $actions->trash($user, $thread);

            return 'Moved to Trash. You can restore it from there.';
        });
    }

    public function restoreThread(): void
    {
        $this->act(function (MailboxActions $actions, User $user, MailThread $thread): string {
            $actions->restore($user, $thread);

            return 'Restored from Trash.';
        });
    }

    public function assign(string $userId): void
    {
        $this->act(function (MailboxActions $actions, User $user, MailThread $thread) use ($userId): string {
            if ($userId === '') {
                $actions->assign($user, $thread, null);

                return 'Assignee removed.';
            }
            $assignee = User::query()->whereKey((int) $userId)->first();
            if ($assignee === null) {
                throw ValidationException::withMessages(['assignee' => 'That person could not be found.']);
            }
            $actions->assign($user, $thread, $assignee);

            return 'Assigned to '.$assignee->name.'.';
        });
    }

    public function togglePriority(): void
    {
        $this->act(function (MailboxActions $actions, User $user, MailThread $thread): string {
            $high = $thread->priority === MailThread::PRIORITY_HIGH;
            $actions->setPriority($user, $thread, $high ? MailThread::PRIORITY_NORMAL : MailThread::PRIORITY_HIGH);

            return $high ? 'Priority set to normal.' : 'Marked high priority.';
        });
    }

    public function snooze(): void
    {
        $this->act(function (MailboxActions $actions, User $user, MailThread $thread): string {
            $until = $this->snoozeUntil($this->snoozeChoice);
            $actions->snooze($user, $thread, $until);

            return 'Snoozed until '.HubTimezone::dateTime($until).'. It returns to the Inbox then.';
        });
    }

    public function unsnooze(): void
    {
        $this->act(function (MailboxActions $actions, User $user, MailThread $thread): string {
            $actions->snooze($user, $thread, null);

            return 'Snooze removed.';
        });
    }

    public function addLabel(): void
    {
        $this->act(function (MailboxActions $actions, User $user, MailThread $thread): string {
            $actions->addLabel($user, $thread, $this->labelName);
            $added = trim($this->labelName);
            $this->labelName = '';

            return 'Label "'.$added.'" added.';
        });
    }

    public function removeLabel(string $name): void
    {
        $this->act(function (MailboxActions $actions, User $user, MailThread $thread) use ($name): string {
            $actions->removeLabel($user, $thread, $name);

            return 'Label "'.$name.'" removed.';
        });
    }

    public function addNote(): void
    {
        $this->act(function (MailboxActions $actions, User $user, MailThread $thread): string {
            $actions->addNote($user, $thread, $this->noteBody);
            $this->noteBody = '';

            return 'Internal note added. It is never sent to anyone.';
        });
    }

    // ---------------------------------------------------------------------------------------------
    // Hand-off to the composer
    // ---------------------------------------------------------------------------------------------

    /** @param  string|null  $source  "inbound:12" / "outbound:7", or null for the newest message people wrote to us */
    public function compose(string $mode, ?string $source = null): void
    {
        $this->authorizeAccess();
        $this->dispatch('mail-compose', mode: $mode, thread: $this->threadId, source: $source);
    }

    public function render(): View
    {
        $user = $this->authorizeAccess();
        $detail = null;
        $state = 'ok';
        try {
            $detail = app(MailboxQuery::class)->thread($user, $this->threadId);
        } catch (ModelNotFoundException) {
            $state = 'missing';
        } catch (MailActionRefused $refused) {
            throw $refused;
        } catch (Throwable $exception) {
            report($exception);
            $state = 'error';
        }

        $extra = $detail instanceof MailThreadDetail ? $this->extras($detail, $user) : [];

        return view('livewire.mail.message-reader', ['detail' => $detail, 'state' => $state, 'viewer' => $user] + $extra);
    }

    /** @return array<string, mixed> */
    private function extras(MailThreadDetail $detail, User $user): array
    {
        $names = User::query()->whereIn('id', $detail->events->pluck('metadata.assignee_user_id')->filter()->all())->pluck('name', 'id')->all();
        $access = app(MailAccess::class);
        $delivery = [];
        foreach ($detail->messages as $message) {
            if ($message->kind === 'outbound' && $message->isHuman && ($email = OutboundEmail::query()->find($message->id)) !== null) {
                $delivery[$message->id] = MailPresentation::deliveryRows($email, $user);
            }
        }

        return [
            'delivery' => $delivery,
            'assigneeNames' => $names,
            'assignable' => $detail->canSend ? $this->assignableUsers() : [],
            'knownLabels' => $detail->canSend ? MailLabel::query()->orderBy('name')->limit(100)->pluck('name')->all() : [],
            'snoozePresets' => $this->snoozePresets(),
            'canManage' => $detail->canManage && $access->canManage($user),
        ];
    }

    /** @return list<array{id: int, name: string}> people who could actually work the conversation */
    private function assignableUsers(): array
    {
        $access = app(MailAccess::class);
        // Looked up by id, not by Spatie's permission() scope, which throws for a permission that does not exist yet.
        $permissionIds = Permission::query()->where('guard_name', 'web')->whereIn('name', [MailAccess::SEND, MailAccess::LEGACY_SEND])->pluck('id')->all();
        $candidates = User::query()->where('account_status', AccountStatus::Active->value)
            ->where(fn ($query) => $query
                ->whereHas('permissions', fn ($permissions) => $permissions->whereIn('permissions.id', $permissionIds))
                ->orWhereHas('roles', fn ($roles) => $roles->where('name', 'super_admin')
                    ->orWhereHas('permissions', fn ($permissions) => $permissions->whereIn('permissions.id', $permissionIds))))
            ->orderBy('name')->limit(200)->get(); // whole rows: the access rule reads status and password flags

        return $candidates->filter(fn (User $candidate): bool => $access->canSend($candidate))
            ->map(fn (User $candidate): array => ['id' => (int) $candidate->getKey(), 'name' => (string) $candidate->name])->values()->all();
    }

    /** @return array<string, string> */
    private function snoozePresets(): array
    {
        return [
            'later' => 'Later today (4 hours)',
            'tomorrow' => 'Tomorrow morning, 8 AM',
            'monday' => 'Monday morning, 8 AM',
            'week' => 'In one week',
        ];
    }

    private function snoozeUntil(string $choice): CarbonImmutable
    {
        $now = CarbonImmutable::now(HubTimezone::NAME);

        return match ($choice) {
            'later' => $now->addHours(4),
            'monday' => $now->next('Monday')->setTime(8, 0),
            'week' => $now->addWeek()->setTime(8, 0),
            default => $now->addDay()->setTime(8, 0),
        };
    }

    /**
     * Runs one action and turns every refusal into a sentence the member can read. A refusal never leaves the
     * page broken: the conversation stays on screen with the message above it.
     *
     * @param  callable(MailboxActions, User, MailThread): ?string  $change  returns the confirmation sentence
     */
    private function act(callable $change): void
    {
        $user = $this->authorizeAccess();
        $this->error = null;
        $this->notice = null;
        try {
            $thread = MailThread::query()->findOrFail($this->threadId);
            $notice = $change(app(MailboxActions::class), $user, $thread);
            $this->notice = is_string($notice) ? $notice : null;
            $this->dispatch('mail-unread-changed');
        } catch (ValidationException $exception) {
            $this->error = (string) collect($exception->errors())->flatten()->first();
        } catch (MailActionRefused $refused) {
            $this->error = $refused->getMessage();
        } catch (ModelNotFoundException) {
            $this->error = 'This conversation is no longer available.';
        } catch (Throwable $exception) {
            report($exception);
            $this->error = 'That did not work, and nothing was changed. Try again.';
        }
    }

    private function authorizeAccess(): User
    {
        $user = auth()->user();
        if (! $user instanceof User || ! app(MailAccess::class)->canView($user)) {
            throw MailActionRefused::forbidden();
        }

        return $user;
    }
}
