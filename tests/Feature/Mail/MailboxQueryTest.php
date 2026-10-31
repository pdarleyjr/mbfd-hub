<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Models\Mail\MailDraft;
use App\Models\Mail\MailLabel;
use App\Models\Mail\MailThread;
use App\Models\Mail\MailThreadUserState;
use App\Services\Mail\MailActionRefused;
use App\Services\Mail\MailboxActions;
use App\Services\Mail\MailboxFilter;
use App\Services\Mail\MailboxQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Mail\Concerns\BuildsMailFixtures;
use Tests\TestCase;

final class MailboxQueryTest extends TestCase
{
    use BuildsMailFixtures;
    use RefreshDatabase;

    private function query(): MailboxQuery
    {
        return app(MailboxQuery::class);
    }

    /** @return list<int> */
    private function subjects(\App\Models\User $user, MailboxFilter $filter): array
    {
        return collect($this->query()->threads($user, $filter)->items())->pluck('subject')->all();
    }

    public function test_every_read_refuses_a_member_without_view_access(): void
    {
        $outsider = $this->mailUser([]);
        $sender = $this->mailUser(['admin.communications.send']);
        $thread = $this->threadOf($this->inbound());

        foreach ([$outsider, $sender] as $user) {
            foreach ([
                fn () => $this->query()->threads($user, MailboxFilter::folder('inbox')),
                fn () => $this->query()->thread($user, $thread->id),
                fn () => $this->query()->counts($user),
                fn () => $this->query()->unreadCount($user),
                fn () => $this->query()->drafts($user),
            ] as $read) {
                try {
                    $read();
                    self::fail('A read was allowed without view access.');
                } catch (MailActionRefused $refused) {
                    self::assertSame(403, $refused->getStatusCode());
                }
            }
        }
    }

    public function test_folders_partition_conversations_and_automated_mail(): void
    {
        $user = $this->mailAdmin();
        $this->inbound(['subject' => 'Open conversation']);
        $archived = $this->inbound(['subject' => 'Archived conversation']);
        $trashed = $this->inbound(['subject' => 'Trashed conversation']);
        $snoozed = $this->inbound(['subject' => 'Snoozed conversation']);
        $this->outbound(['source_type' => 'admin_compose', 'subject' => 'Only sent']);
        $this->outbound(['source_type' => 'password_reset', 'subject' => 'Password reset']);
        $roster = $this->withoutIndexing(function () {
            $email = $this->inbound(['subject' => 'Roster Report']);
            $this->rosterLedgerFor($email);

            return $email;
        });
        app(\App\Services\Mail\MailThreadIndexer::class)->indexInbound($roster);
        $this->threadOf($archived)->forceFill(['archived_at' => now()])->save();
        $this->threadOf($trashed)->forceFill(['trashed_at' => now()])->save();
        $this->threadOf($snoozed)->forceFill(['snoozed_until' => now()->addDay()])->save();

        self::assertEqualsCanonicalizing(['Open conversation'], $this->subjects($user, MailboxFilter::folder('inbox')));
        self::assertEqualsCanonicalizing(['Archived conversation'], $this->subjects($user, MailboxFilter::folder('archive')));
        self::assertEqualsCanonicalizing(['Trashed conversation'], $this->subjects($user, MailboxFilter::folder('trash')));
        self::assertEqualsCanonicalizing(['Only sent'], $this->subjects($user, MailboxFilter::folder('sent')));
        self::assertEqualsCanonicalizing(['Roster Report', 'Password reset'], $this->subjects($user, MailboxFilter::folder('automated')));
    }

    public function test_trash_is_only_for_members_who_can_restore_from_it(): void
    {
        $viewer = $this->mailViewer();
        $thread = $this->threadOf($this->inbound(['subject' => 'Trashed conversation']));
        $thread->forceFill(['trashed_at' => now()])->save();

        self::assertSame([], $this->subjects($viewer, MailboxFilter::folder('trash')));
        self::assertSame(0, $this->query()->counts($viewer)['trash']);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->query()->thread($viewer, $thread->id);
    }

    public function test_a_snooze_hides_the_thread_from_the_inbox_until_it_passes(): void
    {
        $user = $this->mailAdmin();
        $thread = $this->threadOf($this->inbound(['subject' => 'Later']));
        $thread->forceFill(['snoozed_until' => now()->addHours(2)])->save();

        self::assertSame([], $this->subjects($user, MailboxFilter::folder('inbox')));
        $this->travel(3)->hours();
        self::assertSame(['Later'], $this->subjects($user, MailboxFilter::folder('inbox')));
    }

    public function test_unread_is_per_member_and_follows_read_through_and_baseline(): void
    {
        $alice = $this->mailAdmin();
        $bob = $this->mailAdmin();
        $email = $this->inbound(['received_at' => now()->subHour()]);
        $thread = $this->threadOf($email);

        self::assertSame(1, $this->query()->unreadCount($alice));
        self::assertSame(1, $this->query()->unreadCount($bob));

        app(MailboxActions::class)->markRead($alice, $thread);
        self::assertSame(0, $this->query()->unreadCount($alice));
        self::assertSame(1, $this->query()->unreadCount($bob), 'reading is private');

        // New inbound after Alice read: unread again for her, and a baseline older than it does not hide it.
        $thread->forceFill(['unread_baseline_at' => now()->subMinutes(30)])->save();
        $this->inbound(['in_reply_to' => $email->provider_message_id, 'references' => [$email->provider_message_id], 'received_at' => now()->addMinute()]);
        self::assertSame(1, $this->query()->unreadCount($alice));
        self::assertTrue($this->query()->threads($alice, MailboxFilter::folder('inbox'))->items()[0]->getAttribute('is_unread'));
    }

    public function test_the_backfill_baseline_hides_old_mail_for_everyone_and_mark_unread_beats_it(): void
    {
        $user = $this->mailAdmin();
        $email = $this->inbound(['received_at' => now()->subDays(2)]);
        $thread = $this->threadOf($email);
        $thread->forceFill(['unread_baseline_at' => $thread->last_inbound_at])->save();

        self::assertSame(0, $this->query()->unreadCount($user));
        app(MailboxActions::class)->markUnread($user, $thread);
        self::assertSame(1, $this->query()->unreadCount($user));
        self::assertSame(1, $this->query()->threads($user, MailboxFilter::folder('inbox')->onlyUnread())->count());
        app(MailboxActions::class)->markRead($user, $thread);
        self::assertSame(0, $this->query()->unreadCount($user));
    }

    public function test_automated_mail_is_never_unread(): void
    {
        $user = $this->mailAdmin();
        $this->outbound(['source_type' => 'password_reset']);
        $this->inbound(['subject' => 'Normal']);

        self::assertSame(1, $this->query()->unreadCount($user));
        self::assertSame([], $this->subjects($user, MailboxFilter::folder('automated')->onlyUnread()));
    }

    public function test_starred_assigned_labels_and_search_filters(): void
    {
        $user = $this->mailAdmin();
        $other = $this->mailAdmin();
        $this->inbound(['subject' => 'Plain', 'text_body' => 'nothing special']);
        $star = $this->inbound(['subject' => 'Star me']);
        $mine = $this->inbound(['subject' => 'Assigned to me']);
        $withLabel = $this->inbound(['subject' => 'Has label']);
        $withFile = $this->inbound(['subject' => 'Has file', 'attachment_metadata' => [['filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'disk' => 'local', 'path' => 'p']]]);
        $actions = app(MailboxActions::class);
        $actions->star($user, $this->threadOf($star));
        $actions->star($other, $this->threadOf($withLabel));
        $actions->assign($user, $this->threadOf($mine), $user);
        $actions->addLabel($user, $this->threadOf($withLabel), 'Budget');

        self::assertSame(['Star me'], $this->subjects($user, MailboxFilter::folder('starred')));
        self::assertSame(['Has label'], $this->subjects($other, MailboxFilter::folder('starred')));
        self::assertSame(['Assigned to me'], $this->subjects($user, MailboxFilter::folder('assigned')));
        self::assertSame([], $this->subjects($other, MailboxFilter::folder('assigned')));
        self::assertSame(['Has label'], $this->subjects($user, MailboxFilter::folder('inbox')->withLabel('budget')));
        self::assertSame(['Has file'], $this->subjects($user, MailboxFilter::folder('inbox')->onlyWithAttachments()));
        self::assertSame(['Star me'], $this->subjects($user, MailboxFilter::folder('inbox')->withSearch('star me')));
        self::assertSame(['Plain'], $this->subjects($user, MailboxFilter::folder('inbox')->withSearch('nothing SPECIAL')));
        self::assertSame([], $this->subjects($user, MailboxFilter::folder('inbox')->withSearch('100%')), '% is literal, not a wildcard');
        self::assertSame([], $this->subjects($user, MailboxFilter::folder('inbox')->withSearch('\\')));
    }

    public function test_search_looks_in_bodies_and_recipients_but_never_in_bcc_or_notes(): void
    {
        $user = $this->mailAdmin();
        $this->inbound(['subject' => 'A', 'text_body' => 'The hydrant on Collins is leaking']);
        $this->outbound(['subject' => 'B', 'to_recipients' => ['visible.recipient@example.test'], 'bcc_recipients' => ['hidden.bcc@example.test'], 'initiated_by_user_id' => $user->id]);
        $noted = $this->inbound(['subject' => 'C']);
        app(MailboxActions::class)->addNote($user, $this->threadOf($noted), 'secret-note-marker');

        self::assertSame(['A'], $this->subjects($user, MailboxFilter::folder('inbox')->withSearch('hydrant on collins')));
        self::assertSame(['B'], $this->subjects($user, MailboxFilter::folder('sent')->withSearch('visible.recipient')));
        self::assertSame([], $this->subjects($user, MailboxFilter::folder('sent')->withSearch('hidden.bcc')));
        self::assertSame([], $this->subjects($user, MailboxFilter::folder('inbox')->withSearch('secret-note-marker')));
    }

    public function test_cursor_pagination_walks_every_thread_once_newest_first(): void
    {
        $user = $this->mailAdmin();
        foreach (range(1, 7) as $i) {
            $this->inbound(['subject' => "Msg {$i}", 'received_at' => now()->subMinutes(100 - $i)]);
        }

        $seen = [];
        $cursor = null;
        do {
            $page = $this->query()->threads($user, MailboxFilter::folder('inbox'), $cursor, 3);
            array_push($seen, ...collect($page->items())->pluck('subject')->all());
            $cursor = $page->nextCursor()?->encode();
        } while ($cursor !== null);

        self::assertSame(['Msg 7', 'Msg 6', 'Msg 5', 'Msg 4', 'Msg 3', 'Msg 2', 'Msg 1'], $seen);
    }

    public function test_drafts_are_private_and_the_drafts_folder_shows_only_the_members_own(): void
    {
        $alice = $this->mailAdmin();
        $bob = $this->mailAdmin();
        $thread = $this->threadOf($this->inbound(['subject' => 'Drafted on']));
        MailDraft::query()->create(['user_id' => $alice->id, 'thread_id' => $thread->id, 'mode' => 'reply', 'to' => [], 'cc' => [], 'bcc' => []]);
        MailDraft::query()->create(['user_id' => $alice->id, 'mode' => 'new', 'to' => [], 'cc' => [], 'bcc' => []]);

        self::assertSame(['Drafted on'], $this->subjects($alice, MailboxFilter::folder('drafts')));
        self::assertSame([], $this->subjects($bob, MailboxFilter::folder('drafts')));
        self::assertCount(2, $this->query()->drafts($alice));
        self::assertCount(0, $this->query()->drafts($bob));
        self::assertSame(2, $this->query()->counts($alice)['drafts']);
        self::assertSame(0, $this->query()->counts($bob)['drafts']);
        self::assertCount(1, $this->query()->thread($alice, $thread->id)->drafts);
        self::assertCount(0, $this->query()->thread($bob, $thread->id)->drafts);
    }

    public function test_counts_cover_every_folder(): void
    {
        $user = $this->mailAdmin();
        $this->inbound();
        $this->outbound(['source_type' => 'password_reset']);

        $counts = $this->query()->counts($user);

        self::assertSame(MailboxFilter::FOLDERS, array_keys($counts));
        self::assertSame(1, $counts['inbox']);
        self::assertSame(1, $counts['automated']);
        self::assertSame(0, $counts['trash']);
    }

    public function test_thread_detail_hides_bcc_from_everyone_but_the_author_and_never_leaks_html(): void
    {
        $author = $this->mailAdmin();
        $colleague = $this->mailAdmin();
        $sent = $this->outbound([
            'initiated_by_user_id' => $author->id, 'to_recipients' => ['to@example.test'], 'cc_recipients' => ['cc@example.test'],
            'bcc_recipients' => ['quiet@example.test'], 'html_body' => '<p>Rich <script>alert(1)</script></p>',
        ]);
        $thread = $this->threadOf($sent);

        $mine = $this->query()->thread($author, $thread->id)->messages[0];
        $theirs = $this->query()->thread($colleague, $thread->id)->messages[0];

        self::assertSame(['quiet@example.test'], array_column($mine->bcc, 'address'));
        self::assertSame([], $theirs->bcc);
        self::assertTrue($mine->isMine);
        self::assertFalse($theirs->isMine);
        self::assertTrue($theirs->hasHtml);
        self::assertStringNotContainsString('quiet@example.test', json_encode($theirs, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('<script', $theirs->text);
        self::assertSame('Accepted by Cloudflare', $theirs->deliveryStatus);
    }

    public function test_thread_detail_lists_messages_in_order_with_skipped_parts_and_action_flags(): void
    {
        $user = $this->mailAdmin();
        $first = $this->inbound(['subject' => 'Start', 'received_at' => now()->subHours(2)]);
        $first->forceFill(['skipped_attachments' => json_encode([['filename' => 'logo.png', 'mime_type' => 'image/webp', 'size' => 9, 'reason' => 'unsupported_type']])])->save();
        $reply = $this->outbound(['source_type' => 'admin_reply', 'in_reply_to' => $first->provider_message_id, 'references' => [$first->provider_message_id], 'parent_inbound_email_id' => $first->id, 'queued_at' => now()->subHour()]);

        $detail = $this->query()->thread($user, $this->threadOf($first)->id);

        self::assertSame(['inbound', 'outbound'], array_map(fn ($message) => $message->kind, $detail->messages));
        self::assertSame('unsupported_type', $detail->messages[0]->skippedAttachments[0]['reason']);
        self::assertTrue($detail->canAct);
        self::assertTrue($detail->canSend);
        self::assertTrue($detail->isUnread);

        $viewer = $this->mailViewer();
        self::assertFalse($this->query()->thread($viewer, $this->threadOf($first)->id)->canSend);
        $automated = $this->threadOf($this->outbound(['source_type' => 'password_reset']));
        $automatedDetail = $this->query()->thread($user, $automated->id);
        self::assertFalse($automatedDetail->canAct);
        self::assertFalse($automatedDetail->canSend);
    }

    public function test_user_state_rows_are_independent_per_member(): void
    {
        $alice = $this->mailAdmin();
        $bob = $this->mailAdmin();
        $thread = $this->threadOf($this->inbound());
        app(MailboxActions::class)->star($alice, $thread);

        self::assertSame(1, MailThreadUserState::query()->where('user_id', $alice->id)->count());
        self::assertSame(0, MailThreadUserState::query()->where('user_id', $bob->id)->count());
        self::assertSame(0, MailLabel::query()->count());
        self::assertInstanceOf(MailThread::class, $thread);
    }
}
