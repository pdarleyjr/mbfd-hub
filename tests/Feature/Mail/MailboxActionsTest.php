<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Models\InboundEmail;
use App\Models\Mail\MailThread;
use App\Models\Mail\MailThreadEvent;
use App\Models\Mail\MailThreadNote;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Mail\MailActionRefused;
use App\Services\Mail\MailboxActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Mail\Concerns\BuildsMailFixtures;
use Tests\TestCase;

final class MailboxActionsTest extends TestCase
{
    use BuildsMailFixtures;
    use RefreshDatabase;

    private function actions(): MailboxActions
    {
        return app(MailboxActions::class);
    }

    private function thread(): MailThread
    {
        return $this->threadOf($this->inbound());
    }

    /** @return array<string, callable(MailboxActions, User, MailThread): mixed> */
    private function everyAction(): array
    {
        return [
            'markRead' => fn ($a, $u, $t) => $a->markRead($u, $t),
            'markUnread' => fn ($a, $u, $t) => $a->markUnread($u, $t),
            'star' => fn ($a, $u, $t) => $a->star($u, $t),
            'unstar' => fn ($a, $u, $t) => $a->unstar($u, $t),
            'archive' => fn ($a, $u, $t) => $a->archive($u, $t),
            'unarchive' => fn ($a, $u, $t) => $a->unarchive($u, $t),
            'trash' => fn ($a, $u, $t) => $a->trash($u, $t),
            'restore' => fn ($a, $u, $t) => $a->restore($u, $t),
            'assign' => fn ($a, $u, $t) => $a->assign($u, $t, $u),
            'unassign' => fn ($a, $u, $t) => $a->assign($u, $t, null),
            'priority' => fn ($a, $u, $t) => $a->setPriority($u, $t, 'high'),
            'snooze' => fn ($a, $u, $t) => $a->snooze($u, $t, now()->addDay()),
            'unsnooze' => fn ($a, $u, $t) => $a->snooze($u, $t, null),
            'addLabel' => fn ($a, $u, $t) => $a->addLabel($u, $t, 'Budget'),
            'removeLabel' => fn ($a, $u, $t) => $a->removeLabel($u, $t, 'Budget'),
            'addNote' => fn ($a, $u, $t) => $a->addNote($u, $t, 'A note'),
        ];
    }

    public function test_a_member_without_the_communications_capability_can_do_nothing(): void
    {
        $outsider = $this->mailUser([]);
        $thread = $this->thread();

        foreach ($this->everyAction() as $name => $do) {
            try {
                $do($this->actions(), $outsider, $thread);
                self::fail("{$name} was allowed for a member with no access");
            } catch (MailActionRefused $refused) {
                self::assertSame(403, $refused->getStatusCode(), $name);
            }
        }
        self::assertSame(0, MailThreadEvent::query()->count());
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('mail_thread_user_states')->count());
    }

    public function test_a_view_only_member_can_keep_personal_state_but_not_change_the_shared_mailbox(): void
    {
        $viewer = $this->mailViewer();
        $thread = $this->thread();
        $personal = ['markRead', 'markUnread', 'star', 'unstar'];

        foreach ($this->everyAction() as $name => $do) {
            if (in_array($name, $personal, true)) {
                $do($this->actions(), $viewer, $thread);

                continue;
            }
            try {
                $do($this->actions(), $viewer, $thread);
                self::fail("{$name} changed shared state for a view-only member");
            } catch (MailActionRefused $refused) {
                self::assertSame(403, $refused->getStatusCode(), $name);
            }
        }
        $fresh = $thread->fresh();
        self::assertNull($fresh->archived_at);
        self::assertNull($fresh->trashed_at);
        self::assertSame(0, MailThreadNote::query()->count());
    }

    public function test_automated_threads_refuse_every_action_server_side(): void
    {
        $admin = $this->mailAdmin();
        $automated = $this->threadOf($this->outbound(['source_type' => 'password_reset']));
        self::assertSame('automated', $automated->kind);

        foreach ($this->everyAction() as $name => $do) {
            try {
                $do($this->actions(), $admin, $automated);
                self::fail("{$name} was allowed on an automated thread");
            } catch (MailActionRefused $refused) {
                self::assertSame(403, $refused->getStatusCode(), $name);
                self::assertStringContainsString('read-only', $refused->getMessage());
            }
        }
        $fresh = $automated->fresh();
        self::assertNull($fresh->archived_at);
        self::assertNull($fresh->trashed_at);
        self::assertSame(0, MailThreadEvent::query()->count());
    }

    public function test_roster_mail_threads_refuse_actions_too(): void
    {
        $admin = $this->mailAdmin();
        $roster = $this->withoutIndexing(function (): InboundEmail {
            $email = $this->inbound(['from_address' => 'donotreply@miamibeachfl.gov']);
            $this->rosterLedgerFor($email);

            return $email;
        });
        $thread = app(\App\Services\Mail\MailThreadIndexer::class)->indexInbound($roster);

        $this->expectException(MailActionRefused::class);
        $this->actions()->trash($admin, $thread);
    }

    public function test_a_trashed_thread_refuses_shared_changes_until_it_is_restored(): void
    {
        $admin = $this->mailAdmin();
        $thread = $this->thread();
        $this->actions()->trash($admin, $thread);
        self::assertNotNull($thread->trashed_at);

        foreach (['archive', 'assign', 'priority', 'snooze', 'addLabel', 'addNote', 'trash'] as $name) {
            try {
                $this->everyAction()[$name]($this->actions(), $admin, $thread);
                self::fail("{$name} worked on a trashed thread");
            } catch (MailActionRefused $refused) {
                self::assertSame(409, $refused->getStatusCode(), $name);
            }
        }
        $this->actions()->star($admin, $thread); // still personal
        $this->actions()->restore($admin, $thread);
        self::assertNull($thread->trashed_at);
        $this->actions()->archive($admin, $thread);
        self::assertNotNull($thread->archived_at);
    }

    public function test_archive_unarchive_trash_restore_and_their_audit_events(): void
    {
        $admin = $this->mailAdmin();
        $thread = $this->thread();

        $this->actions()->archive($admin, $thread);
        $this->actions()->archive($admin, $thread); // idempotent: no second event
        $this->actions()->unarchive($admin, $thread);
        $this->actions()->trash($admin, $thread);
        $this->actions()->restore($admin, $thread);

        self::assertNull($thread->archived_at);
        self::assertNull($thread->trashed_at);
        self::assertSame(['archived', 'unarchived', 'trashed', 'restored'], MailThreadEvent::query()->orderBy('id')->pluck('type')->all());
        self::assertSame($admin->id, MailThreadEvent::query()->first()->user_id);
    }

    public function test_assignment_priority_and_snooze_validate_their_input(): void
    {
        $admin = $this->mailAdmin();
        $colleague = $this->mailAdmin();
        $viewer = $this->mailViewer();
        $thread = $this->thread();

        $this->actions()->assign($admin, $thread, $colleague);
        self::assertSame($colleague->id, $thread->assigned_user_id);
        $this->actions()->assign($admin, $thread, null);
        self::assertNull($thread->assigned_user_id);

        foreach ([
            fn () => $this->actions()->assign($admin, $thread, $viewer),
            fn () => $this->actions()->assign($admin, $thread, $this->mailUser([])),
            fn () => $this->actions()->setPriority($admin, $thread, 'urgent'),
            fn () => $this->actions()->snooze($admin, $thread, now()->subMinute()),
            fn () => $this->actions()->snooze($admin, $thread, now()->addYears(2)),
            fn () => $this->actions()->addNote($admin, $thread, "   \n"),
            fn () => $this->actions()->addNote($admin, $thread, str_repeat('x', 10001)),
            fn () => $this->actions()->addLabel($admin, $thread, ''),
            fn () => $this->actions()->addLabel($admin, $thread, "bad\x00label"),
            fn () => $this->actions()->addLabel($admin, $thread, '<script>'),
            fn () => $this->actions()->addLabel($admin, $thread, str_repeat('l', 41)),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('invalid input was accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->actions()->setPriority($admin, $thread, 'high');
        self::assertSame('high', $thread->priority);
        $until = now()->addHours(3);
        $this->actions()->snooze($admin, $thread, $until);
        self::assertEquals($until->timestamp, $thread->snoozed_until->timestamp);
        $this->actions()->snooze($admin, $thread, null);
        self::assertNull($thread->snoozed_until);
    }

    public function test_labels_are_shared_case_insensitive_and_capped(): void
    {
        $admin = $this->mailAdmin();
        $thread = $this->thread();
        $other = $this->thread();

        $this->actions()->addLabel($admin, $thread, 'Budget  Q4');
        $this->actions()->addLabel($admin, $thread, 'budget q4'); // same label, same thread: no duplicate
        $this->actions()->addLabel($admin, $other, 'BUDGET Q4');
        self::assertSame(['Budget Q4'], $thread->labels()->pluck('name')->all());
        self::assertSame(1, \App\Models\Mail\MailLabel::query()->count());
        self::assertSame(['Budget Q4'], $other->labels()->pluck('name')->all());

        $this->actions()->removeLabel($admin, $thread, 'BUDGET q4');
        self::assertSame([], $thread->labels()->pluck('name')->all());
        self::assertSame(1, $other->labels()->count());

        foreach (range(1, 12) as $i) {
            $this->actions()->addLabel($admin, $thread, "L{$i}");
        }
        $this->expectException(ValidationException::class);
        $this->actions()->addLabel($admin, $thread, 'One too many');
    }

    public function test_notes_are_stored_with_their_author_and_logged(): void
    {
        $admin = $this->mailAdmin();
        $thread = $this->thread();

        $note = $this->actions()->addNote($admin, $thread, '  Called the vendor.  ');

        self::assertSame('Called the vendor.', $note->body);
        self::assertSame($admin->id, $note->user_id);
        self::assertSame(['note_added'], MailThreadEvent::query()->pluck('type')->all());
        self::assertNull(MailThreadEvent::query()->first()->metadata, 'the event never carries the note text');
    }

    public function test_no_action_ever_touches_message_rows_or_the_send_ledger(): void
    {
        $admin = $this->mailAdmin();
        $inbound = $this->inbound();
        $outbound = $this->outbound();
        $thread = $this->threadOf($inbound);
        $before = [InboundEmail::query()->get()->map->getAttributes()->all(), OutboundEmail::query()->get()->map->getAttributes()->all()];

        foreach ($this->everyAction() as $do) {
            $thread->refresh();
            if ($thread->trashed_at !== null) {
                $this->actions()->restore($admin, $thread);
            }
            $do($this->actions(), $admin, $thread);
        }

        self::assertSame($before, [InboundEmail::query()->get()->map->getAttributes()->all(), OutboundEmail::query()->get()->map->getAttributes()->all()]);
        self::assertSame(1, OutboundEmail::query()->count());
        self::assertSame(1, OutboundEmail::query()->where('id', $outbound->id)->count());
    }
}
