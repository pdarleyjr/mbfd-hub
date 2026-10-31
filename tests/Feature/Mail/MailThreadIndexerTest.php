<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Models\InboundEmail;
use App\Models\Mail\MailLabel;
use App\Models\Mail\MailThread;
use App\Models\Mail\MailThreadMessage;
use App\Models\Mail\MailThreadNote;
use App\Models\Mail\MailThreadUserState;
use App\Services\Mail\MailIndexSweeper;
use App\Services\Mail\MailThreadIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Mail\Concerns\BuildsMailFixtures;
use Tests\TestCase;

final class MailThreadIndexerTest extends TestCase
{
    use BuildsMailFixtures;
    use RefreshDatabase;

    private function indexer(): MailThreadIndexer
    {
        return app(MailThreadIndexer::class);
    }

    public function test_a_new_inbound_message_becomes_its_own_conversation_after_commit(): void
    {
        $email = $this->inbound([
            'from_address' => 'chief@example.test', 'from_display_name' => 'Chief Rivera', 'subject' => 'Re: Budget review',
            'text_body' => "Please confirm.\n\nOn Mon, Oct 26, 2026 Someone wrote:\n> older",
            'safe_headers' => ['to' => ['info@mbfdhub.com', 'deputy@example.test'], 'cc' => [], 'reply-to' => null],
        ]);

        $thread = $this->threadOf($email);

        self::assertNotNull($thread, 'the created hook should have indexed the message');
        self::assertSame('conversation', $thread->kind);
        self::assertSame('Budget review', $thread->subject);
        self::assertSame('Please confirm.', $thread->snippet);
        self::assertSame(1, $thread->message_count);
        self::assertFalse($thread->has_attachments);
        self::assertEquals($email->received_at, $thread->last_inbound_at);
        $addresses = array_column($thread->participants, 'address');
        self::assertContains('chief@example.test', $addresses);
        self::assertContains('deputy@example.test', $addresses);
        self::assertNotContains('info@mbfdhub.com', $addresses, 'the Hub address heads every thread and is noise');
        self::assertSame('Chief Rivera', collect($thread->participants)->firstWhere('address', 'chief@example.test')['name']);
    }

    public function test_replies_thread_by_headers_foreign_keys_and_references_never_by_subject(): void
    {
        $first = $this->inbound(['subject' => 'Hydrant inspection']);
        $reply = $this->outbound([
            'source_type' => 'admin_reply', 'subject' => 'Re: Hydrant inspection',
            'in_reply_to' => $first->provider_message_id, 'references' => [$first->provider_message_id],
            'parent_inbound_email_id' => $first->getKey(),
        ]);
        $answer = $this->inbound([
            'subject' => 'Totally different words', 'in_reply_to' => $reply->message_id,
            'references' => [$first->provider_message_id, $reply->message_id],
        ]);
        // Only the references header links this one: its direct parent is not in the Hub at all.
        $late = $this->inbound(['subject' => 'x', 'in_reply_to' => '<missing.parent@elsewhere.test>', 'references' => [$first->provider_message_id, '<missing.parent@elsewhere.test>']]);
        // Same subject, no link: must stay apart.
        $lookalike = $this->inbound(['subject' => 'Hydrant inspection']);
        $fkOnly = $this->outbound(['source_type' => 'admin_reply', 'subject' => 'unrelated words', 'parent_inbound_email_id' => $first->getKey()]);

        $thread = $this->threadOf($first);
        self::assertEquals($thread->id, $this->threadOf($reply)->id);
        self::assertEquals($thread->id, $this->threadOf($answer)->id);
        self::assertEquals($thread->id, $this->threadOf($late)->id);
        self::assertEquals($thread->id, $this->threadOf($fkOnly)->id);
        self::assertNotEquals($thread->id, $this->threadOf($lookalike)->id);
        self::assertSame(5, $thread->fresh()->message_count);
        self::assertSame('Hydrant inspection', $thread->fresh()->subject, 'named for the first message, prefixes stripped');
    }

    public function test_an_inbound_reply_links_to_its_outbound_parent_through_the_existing_parent_key(): void
    {
        $sent = $this->outbound(['source_type' => 'admin_compose']);
        $reply = $this->inbound(['parent_outbound_email_id' => $sent->getKey()]);

        self::assertEquals($this->threadOf($sent)->id, $this->threadOf($reply)->id);
    }

    public function test_outbound_message_ids_match_with_or_without_angle_brackets(): void
    {
        $sent = $this->outbound(['message_id' => null, 'provider_message_id' => 'opaque.provider.id@cf.test']);
        $reply = $this->inbound(['in_reply_to' => '<opaque.provider.id@cf.test>', 'references' => ['<opaque.provider.id@cf.test>']]);

        self::assertEquals($this->threadOf($sent)->id, $this->threadOf($reply)->id);
    }

    public function test_a_forward_starts_its_own_conversation_even_though_it_points_at_the_original(): void
    {
        $original = $this->inbound();
        $forward = $this->outbound(['source_type' => 'admin_forward', 'parent_inbound_email_id' => $original->getKey(), 'subject' => 'Fwd: '.$original->subject]);

        self::assertNotEquals($this->threadOf($original)->id, $this->threadOf($forward)->id);
    }

    public function test_a_reply_indexed_before_its_parent_merges_when_the_parent_arrives(): void
    {
        $parent = $this->withoutIndexing(fn () => $this->inbound(['subject' => 'Parent']));
        $reply = $this->inbound(['subject' => 'Re: Parent', 'in_reply_to' => $parent->provider_message_id, 'references' => [$parent->provider_message_id]]);
        $replyThread = $this->threadOf($reply);
        self::assertNotNull($replyThread);
        self::assertNull($this->threadOf($parent));

        $this->indexer()->indexInbound($parent);

        self::assertEquals($this->threadOf($parent)->id, $this->threadOf($reply)->id);
        self::assertSame(2, MailThread::query()->sole()->message_count);
        self::assertSame('Parent', MailThread::query()->sole()->subject);
    }

    public function test_the_sweep_repairs_order_and_a_second_run_changes_nothing(): void
    {
        [$parent, $reply, $grandchild] = $this->withoutIndexing(function (): array {
            $parent = $this->inbound(['received_at' => now()->subHours(5)]);
            $reply = $this->outbound(['in_reply_to' => $parent->provider_message_id, 'references' => [$parent->provider_message_id], 'queued_at' => now()->subHours(4)]);
            $grandchild = $this->inbound(['in_reply_to' => $reply->message_id, 'references' => [$parent->provider_message_id, $reply->message_id], 'received_at' => now()->subHours(3)]);

            return [$parent, $reply, $grandchild];
        });
        self::assertSame(0, MailThread::query()->count());

        $first = app(MailIndexSweeper::class)->run();

        self::assertSame(3, $first['indexed']);
        self::assertSame(1, MailThread::query()->count());
        self::assertSame(3, MailThreadMessage::query()->count());
        $snapshot = MailThread::query()->get()->map->getAttributes()->all();

        $this->travel(2)->minutes();
        $second = app(MailIndexSweeper::class)->run();

        self::assertSame(0, $second['indexed']);
        self::assertSame(0, $second['errors']);
        self::assertSame($snapshot, MailThread::query()->get()->map->getAttributes()->all(), 'a second sweep must not rewrite anything');
        self::assertSame(3, MailThreadMessage::query()->count());
        self::assertEquals($this->threadOf($parent)->id, $this->threadOf($grandchild)->id);
    }

    public function test_indexing_the_same_message_twice_is_a_no_op(): void
    {
        $email = $this->inbound();
        $this->indexer()->indexInbound($email);
        $this->indexer()->indexInbound($email->getKey());

        self::assertSame(1, MailThreadMessage::query()->count());
        self::assertSame(1, MailThread::query()->count());
    }

    public function test_a_message_linking_two_threads_merges_them_and_keeps_human_state(): void
    {
        $user = $this->mailAdmin();
        $one = $this->inbound();
        $two = $this->inbound();
        $threadOne = $this->threadOf($one);
        $threadTwo = $this->threadOf($two);
        MailThreadNote::query()->create(['thread_id' => $threadTwo->id, 'user_id' => $user->id, 'body' => 'internal']);
        $label = MailLabel::query()->create(['name' => 'Budget']);
        $threadTwo->labels()->attach($label->id);
        MailThreadUserState::query()->create(['thread_id' => $threadTwo->id, 'user_id' => $user->id, 'starred_at' => now()]);
        $threadTwo->forceFill(['priority' => 'high'])->save();

        $both = $this->inbound(['in_reply_to' => $one->provider_message_id, 'references' => [$one->provider_message_id, $two->provider_message_id]]);

        self::assertSame(1, MailThread::query()->count());
        $merged = MailThread::query()->sole();
        self::assertEquals($threadOne->id, $merged->id, 'the older thread survives');
        self::assertSame(3, $merged->message_count);
        self::assertSame('high', $merged->priority);
        self::assertSame(1, MailThreadNote::query()->where('thread_id', $merged->id)->count());
        self::assertSame(['Budget'], $merged->labels->pluck('name')->all());
        self::assertNotNull(MailThreadUserState::query()->where('thread_id', $merged->id)->where('user_id', $user->id)->value('starred_at'));
        self::assertEquals($merged->id, $this->threadOf($both)->id);
        self::assertEquals($merged->id, $this->threadOf($two)->id);
    }

    public function test_thread_kind_follows_the_messages(): void
    {
        $roster = $this->withoutIndexing(function (): InboundEmail {
            $email = $this->inbound(['from_address' => 'donotreply@miamibeachfl.gov', 'subject' => 'Roster Report']);
            $this->rosterLedgerFor($email);

            return $email;
        });
        $this->indexer()->indexInbound($roster);
        $ordinary = $this->inbound();
        $notification = $this->outbound(['source_type' => 'App\\Notifications\\NewSubmissionNotification']);
        $humanOnly = $this->outbound(['source_type' => 'admin_compose']);
        $replyToNotification = $this->inbound(['in_reply_to' => $notification->message_id, 'references' => [$notification->message_id]]);

        self::assertSame('automated', $this->threadOf($roster)->kind);
        self::assertSame('conversation', $this->threadOf($ordinary)->kind);
        self::assertSame('conversation', $this->threadOf($humanOnly)->kind);
        self::assertSame('conversation', $this->threadOf($replyToNotification)->kind, 'a person answering a system message makes it a conversation');
        self::assertEquals($this->threadOf($notification)->id, $this->threadOf($replyToNotification)->id);
        $systemOnly = $this->outbound(['source_type' => 'password_reset']);
        self::assertSame('automated', $this->threadOf($systemOnly)->kind);
    }

    public function test_roster_threads_are_sealed_against_forged_threading_headers(): void
    {
        $humanThread = $this->inbound(['subject' => 'Real conversation']);
        $roster = $this->withoutIndexing(function (): InboundEmail {
            $email = $this->inbound(['from_address' => 'donotreply@miamibeachfl.gov', 'subject' => 'Roster Report']);
            $this->rosterLedgerFor($email);

            return $email;
        });
        $this->indexer()->indexInbound($roster);

        // A stranger quotes both ids, hoping to bury their mail in the read-only folder or freeze the conversation.
        $forged = $this->inbound(['from_address' => 'stranger@example.test', 'in_reply_to' => $roster->provider_message_id, 'references' => [$humanThread->provider_message_id, $roster->provider_message_id]]);

        self::assertNotEquals($this->threadOf($roster)->id, $this->threadOf($forged)->id);
        self::assertSame('automated', $this->threadOf($roster)->kind);
        self::assertSame(1, $this->threadOf($roster)->message_count);
        self::assertSame('conversation', $this->threadOf($forged)->kind);
        self::assertEquals($this->threadOf($humanThread)->id, $this->threadOf($forged)->id, 'it still joins the real conversation it names');
    }

    public function test_new_inbound_mail_brings_archived_snoozed_and_trashed_threads_back_and_outbound_never_does(): void
    {
        $archived = $this->inbound();
        $snoozed = $this->inbound();
        $trashed = $this->inbound();
        $this->threadOf($archived)->forceFill(['archived_at' => now()->subDay()])->save();
        $this->threadOf($snoozed)->forceFill(['snoozed_until' => now()->addDay(), 'archived_at' => now()->subHour()])->save();
        $this->threadOf($trashed)->forceFill(['trashed_at' => now()->subDay(), 'archived_at' => now()->subDay()])->save();

        $this->outbound(['in_reply_to' => $archived->provider_message_id, 'references' => [$archived->provider_message_id], 'parent_inbound_email_id' => $archived->getKey(), 'source_type' => 'admin_reply']);
        self::assertNotNull($this->threadOf($archived)->archived_at, 'sending a reply does not reopen');

        foreach ([$archived, $snoozed, $trashed] as $original) {
            $this->inbound(['in_reply_to' => $original->provider_message_id, 'references' => [$original->provider_message_id], 'received_at' => now()]);
        }

        self::assertNull($this->threadOf($archived)->archived_at);
        self::assertNull($this->threadOf($snoozed)->archived_at);
        self::assertNull($this->threadOf($snoozed)->snoozed_until);
        self::assertNull($this->threadOf($trashed)->trashed_at, 'a reply to a trashed conversation must not land where nobody looks');
        self::assertNull($this->threadOf($trashed)->archived_at);
    }

    public function test_the_scheduled_sweep_never_marks_already_indexed_mail_as_read(): void
    {
        $email = $this->inbound(['received_at' => now()->subHours(2)]);
        self::assertNull($this->threadOf($email)->unread_baseline_at);

        $this->artisan('mail:index-sweep')->assertSuccessful();

        self::assertNull($this->threadOf($email)->fresh()->unread_baseline_at, 'the sweep re-checks recent mail; it must not read it for everyone');
    }

    public function test_telestaff_mail_without_a_roster_row_is_still_sealed_as_automated(): void
    {
        $roster = $this->inbound([
            'from_address' => 'donotreply@miamibeachfl.gov',
            'subject' => 'Roster Report',
            'attachment_metadata' => [['filename' => 'Dailyroster.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size' => 10]],
        ]);
        $forged = $this->inbound(['from_address' => 'stranger@example.test', 'in_reply_to' => $roster->provider_message_id, 'references' => [$roster->provider_message_id]]);

        self::assertSame(0, \App\Models\DailyRoster\RosterImport::query()->count());
        self::assertSame('automated', $this->threadOf($roster)->kind);
        self::assertSame(1, $this->threadOf($roster)->message_count);
        self::assertSame('conversation', $this->threadOf($forged)->kind);
        self::assertNotEquals($this->threadOf($roster)->id, $this->threadOf($forged)->id);
    }

    public function test_backfill_marks_old_mail_read_and_leaves_recent_mail_unread(): void
    {
        $this->withoutIndexing(function (): void {
            $this->inbound(['received_at' => now()->subDays(3)]);
            $this->inbound(['received_at' => now()->subMinutes(5)]);
        });

        app(MailIndexSweeper::class)->run(now()->subMinutes(60));

        $old = MailThread::query()->orderBy('id')->first();
        $recent = MailThread::query()->orderBy('id')->skip(1)->first();
        self::assertEquals($old->last_inbound_at, $old->unread_baseline_at, 'existing mail starts read');
        self::assertNull($recent->unread_baseline_at, 'mail the hooks missed minutes ago is still new');
    }

    public function test_cleanup_deleting_the_inbound_row_leaves_nothing_broken_and_the_sweep_prunes_empty_threads(): void
    {
        $roster = $this->withoutIndexing(function (): InboundEmail {
            $email = $this->inbound(['from_address' => 'donotreply@miamibeachfl.gov']);
            $this->rosterLedgerFor($email);

            return $email;
        });
        $this->indexer()->indexInbound($roster);
        $conversation = $this->inbound();
        $reply = $this->outbound(['source_type' => 'admin_reply', 'in_reply_to' => $conversation->provider_message_id, 'references' => [$conversation->provider_message_id], 'parent_inbound_email_id' => $conversation->getKey()]);
        self::assertSame(2, MailThread::query()->count());

        // Exactly what RosterImportCleanup does.
        $roster->delete();
        self::assertSame(0, MailThreadMessage::query()->where('inbound_email_id', $roster->id)->count());

        $stats = app(MailIndexSweeper::class)->run();

        self::assertSame(1, $stats['pruned']);
        self::assertSame(1, MailThread::query()->count());
        self::assertEquals($this->threadOf($conversation)->id, $this->threadOf($reply)->id);
    }

    public function test_a_thread_that_loses_one_of_its_messages_is_recomputed(): void
    {
        $first = $this->inbound(['subject' => 'Kept']);
        $second = $this->inbound(['in_reply_to' => $first->provider_message_id, 'references' => [$first->provider_message_id], 'text_body' => 'Second body']);
        $thread = $this->threadOf($first);
        self::assertSame(2, $thread->fresh()->message_count);
        // Old enough that the sweep's recent-message recheck does not get there first.
        InboundEmail::query()->update(['created_at' => now()->subDays(5)]);

        $second->delete();
        $stats = app(MailIndexSweeper::class)->run();

        self::assertSame(1, $stats['repaired']);
        self::assertSame(1, $thread->fresh()->message_count);
        self::assertSame('Body 1', $thread->fresh()->snippet, 'the snippet follows the newest message that is left');
    }

    public function test_attachments_set_the_thread_flag_but_skipped_parts_do_not(): void
    {
        $withFile = $this->inbound(['attachment_metadata' => [['filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 4, 'disk' => 'local', 'path' => 'x']]]);
        $without = $this->inbound();

        self::assertTrue($this->threadOf($withFile)->has_attachments);
        self::assertFalse($this->threadOf($without)->has_attachments);
    }
}
