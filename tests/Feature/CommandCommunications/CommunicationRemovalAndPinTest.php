<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCommunications;

use App\Models\CommandCommunicationAuditEvent;
use App\Models\CommandCommunicationPost;
use App\Services\CommandCommunications\CommandCommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\CommandCommunications\Concerns\BuildsSharedCommunicationsWorld;
use Tests\TestCase;

/** Controlled removal (author or communications administrator, with a reason) and channel-aware pinning, both audited. */
final class CommunicationRemovalAndPinTest extends TestCase
{
    use BuildsSharedCommunicationsWorld;
    use RefreshDatabase;

    private const C300 = 'command_300';

    private const C5 = 'captain_5';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCommunicationsWorld();
    }

    public function test_an_author_removes_their_own_message_with_a_reason_and_leaves_a_tombstone_and_an_audit_row(): void
    {
        $author = $this->command300Member('Casey Author');
        $reader = $this->command300Member('Reader');
        $id = $this->postVia($author, self::C300, 'The secret staffing plan');
        $post = CommandCommunicationPost::query()->where('public_id', $id)->firstOrFail();

        $this->actAs($author);
        $response = $this->deleteJson($this->commsBase(self::C300).'/posts/'.$id, ['reason' => 'Posted in the wrong place'])->assertOk();
        $response->assertJsonPath('data.deleted', true)->assertJsonPath('data.body', '')->assertJsonPath('data.subject', null)
            ->assertJsonPath('data.attachments', [])->assertJsonPath('data.removed.by', 'Casey Author')->assertJsonPath('data.can_delete', false);

        $fresh = $post->fresh();
        $this->assertNotNull($fresh->deleted_at);
        $this->assertSame($author->id, $fresh->deleted_by_user_id);
        $this->assertSame('Posted in the wrong place', $fresh->deletion_reason);

        $audit = CommandCommunicationAuditEvent::query()->where('post_id', $post->id)->get();
        $this->assertCount(1, $audit);
        $this->assertSame([$author->id, 'deleted', 'Posted in the wrong place'], [$audit[0]->actor_user_id, $audit[0]->action, $audit[0]->reason]);

        // Every reader sees a tombstone instead of the message, and nothing of its text leaks.
        $this->actAs($reader);
        $feed = $this->getJson($this->commsBase(self::C300).'/posts')->assertOk();
        $feed->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.deleted', true)
            ->assertJsonPath('data.0.removed.by', 'Casey Author')->assertJsonPath('data.0.pinned', false);
        $this->assertStringNotContainsString('secret staffing plan', (string) $feed->getContent());
        $this->assertStringNotContainsString('wrong place', (string) $feed->getContent(), 'The reason stays in the audit record');
        $this->assertSame(0, $feed->json('unread_count'), 'A removed message is not unread');
    }

    public function test_reply_threads_stay_intact_around_a_removed_post_and_a_removed_reply(): void
    {
        $author = $this->command300Member('Thread Author');
        $other = $this->command300Member('Other Member');
        $top = $this->postVia($author, self::C300, 'Top message');
        $keep = $this->postVia($other, self::C300, 'A reply that stays', $top);
        $drop = $this->postVia($author, self::C300, 'A reply to drop', $top);

        $this->actAs($author);
        $this->deleteJson($this->commsBase(self::C300).'/posts/'.$drop, ['reason' => 'Typo'])->assertOk()
            ->assertJsonPath('data.deleted', true)->assertJsonPath('data.parent_id', $top);
        $thread = $this->getJson($this->commsBase(self::C300).'/posts')->json('data.0');
        $this->assertSame('Top message', $thread['body']);
        $this->assertSame([false, true], array_column($thread['replies'], 'deleted'));
        $this->assertSame('A reply that stays', $thread['replies'][0]['body']);
        $this->assertSame('', $thread['replies'][1]['body']);

        $this->deleteJson($this->commsBase(self::C300).'/posts/'.$top, ['reason' => 'Whole thing was premature'])->assertOk();
        $thread = $this->getJson($this->commsBase(self::C300).'/posts')->json('data.0');
        $this->assertTrue($thread['deleted']);
        $this->assertCount(2, $thread['replies'], 'The replies stay attached to the removed post');
        $this->assertSame('A reply that stays', $thread['replies'][0]['body']);

        // Nobody can reply to a removed message.
        $this->actAs($other);
        $this->postJson($this->commsBase(self::C300).'/posts', ['body' => 'late', 'parent_id' => $top, 'client_submission_id' => $this->submissionId()])->assertUnprocessable();
        $this->assertSame(2, CommandCommunicationPost::query()->whereNotNull('deleted_at')->count());
        $this->assertSame(2, CommandCommunicationAuditEvent::query()->where('action', 'deleted')->count());
        $this->assertNull(CommandCommunicationPost::query()->where('public_id', $keep)->value('deleted_at'));
    }

    public function test_others_cannot_remove_a_message_and_a_reason_is_required(): void
    {
        $author = $this->command300Member('Author');
        $other = $this->command300Member('Bystander');
        $viewer = $this->communicationsViewer(); // administrator who may view, not moderate
        $id = $this->postVia($author, self::C300, 'Keep me');

        foreach ([$other, $viewer] as $stranger) {
            $this->actAs($stranger);
            $this->deleteJson($this->commsBase(self::C300).'/posts/'.$id, ['reason' => 'I do not like it'])->assertForbidden();
        }

        $this->actAs($author);
        foreach ([[], ['reason' => ''], ['reason' => 'ab'], ['reason' => str_repeat('x', 501)]] as $payload) {
            $this->deleteJson($this->commsBase(self::C300).'/posts/'.$id, $payload)->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->deleteJson($this->commsBase(self::C300).'/posts/01ARZ3NDEKTSV4RRFFQ69G5FAV', ['reason' => 'Missing post'])->assertNotFound();

        $this->assertNull(CommandCommunicationPost::query()->where('public_id', $id)->value('deleted_at'));
        $this->assertSame(0, CommandCommunicationAuditEvent::query()->count(), 'Refused removals leave no audit row');
    }

    public function test_a_moderator_removes_anyones_message_and_a_both_post_disappears_from_both_feeds(): void
    {
        $moderator = $this->communicationsModerator();
        $lieutenant = $this->captain5Viewer();
        $member300 = $this->command300Member();
        $both = $this->adminPost($this->superAdmin(), 'both', 'Sent in error');
        $fromLieutenant = $this->postVia($lieutenant, self::C5, 'A Captain 5 question');

        $this->actAs($moderator);
        $this->deleteJson($this->commsBase(self::C5).'/posts/'.$fromLieutenant, ['reason' => 'Duplicate of another thread'])->assertOk();
        $this->deleteJson($this->commsBase(self::C300).'/posts/'.$both->public_id, ['reason' => 'Wrong audience'])->assertOk();

        $this->assertEqualsCanonicalizing(
            [$moderator->id, $moderator->id],
            CommandCommunicationPost::query()->whereNotNull('deleted_at')->pluck('deleted_by_user_id')->all(),
        );
        foreach ([[$member300, self::C300, 1], [$lieutenant, self::C5, 2]] as [$user, $channel, $expected]) {
            $this->actAs($user);
            $posts = $this->getJson($this->commsBase($channel).'/posts')->json('data');
            $this->assertSame(array_fill(0, $expected, true), array_column($posts, 'deleted'), 'The shared record is a tombstone in every feed');
            $this->assertStringNotContainsString('Sent in error', json_encode($posts, JSON_THROW_ON_ERROR));
        }

        // The super administrator can remove too (protected recovery).
        $another = $this->postVia($member300, self::C300, 'Another');
        $this->actAs($this->superAdmin());
        $this->deleteJson($this->commsBase(self::C300).'/posts/'.$another, ['reason' => 'Housekeeping'])->assertOk();
    }

    public function test_removal_cannot_reach_a_post_outside_the_channel_and_cannot_repeat(): void
    {
        $moderator = $this->communicationsModerator();
        $lieutenant = $this->captain5Viewer();
        $only300 = $this->postVia($this->command300Member(), self::C300, 'Only 300 sees this');
        $only5 = $this->postVia($lieutenant, self::C5, 'Only Captain 5 sees this');

        // The route's channel decides: a 300 post is not addressable through the Captain 5 API and vice versa.
        $this->actAs($moderator);
        $this->deleteJson($this->commsBase(self::C5).'/posts/'.$only300, ['reason' => 'Wrong door'])->assertNotFound();
        $this->deleteJson($this->commsBase(self::C300).'/posts/'.$only5, ['reason' => 'Wrong door'])->assertNotFound();
        $this->actAs($lieutenant);
        $this->deleteJson($this->commsBase(self::C5).'/posts/'.$only300, ['reason' => 'Not mine'])->assertNotFound();
        $this->assertSame(0, CommandCommunicationPost::query()->whereNotNull('deleted_at')->count());

        $this->deleteJson($this->commsBase(self::C5).'/posts/'.$only5, ['reason' => 'Done with it'])->assertOk();
        $this->deleteJson($this->commsBase(self::C5).'/posts/'.$only5, ['reason' => 'Done with it'])->assertUnprocessable();
        $this->assertSame(1, CommandCommunicationAuditEvent::query()->count(), 'A second attempt adds nothing');
    }

    public function test_removed_text_is_not_searchable_and_not_pinned(): void
    {
        $author = $this->command300Member();
        $service = app(CommandCommunicationService::class);
        $keep = $service->post($author, 'command', 'Hydrant plan', 'Zebra crossing', null, $this->submissionId());
        $drop = $service->post($author, 'command', null, 'Zebra removed', null, $this->submissionId());
        $service->setPinned($author, $drop, true);
        $this->assertCount(1, $service->pinned($author));

        $service->delete($author, $drop, 'Not needed any more');

        $this->assertSame([$keep->public_id], $service->search($author, 'zebra')->pluck('public_id')->all());
        $this->assertCount(0, $service->pinned($author));
        $this->assertSame(1, $service->unreadCount($this->command300Member('Fresh Reader')), 'Only the live message is unread');
    }

    public function test_removal_and_its_audit_row_commit_together_or_not_at_all(): void
    {
        $author = $this->command300Member();
        $post = app(CommandCommunicationService::class)->post($author, 'command', null, 'Atomic', null, $this->submissionId());

        Schema::rename('command_communication_audit_events', 'command_communication_audit_events_off');
        try {
            $failed = false;
            try {
                app(CommandCommunicationService::class)->delete($author, $post, 'This will roll back');
            } catch (\Throwable) {
                $failed = true;
            }
        } finally {
            Schema::rename('command_communication_audit_events_off', 'command_communication_audit_events');
        }

        $this->assertTrue($failed);
        $this->assertNull($post->fresh()->deleted_at, 'The removal rolled back with the missing audit row');
        $this->assertNull($post->fresh()->deletion_reason);
        $this->assertSame(0, CommandCommunicationAuditEvent::query()->count());
    }

    public function test_the_audit_trail_is_append_only(): void
    {
        $author = $this->command300Member();
        $service = app(CommandCommunicationService::class);
        $post = $service->post($author, 'command', null, 'Audited', null, $this->submissionId());
        $service->delete($author, $post, 'Audit me');
        $event = CommandCommunicationAuditEvent::query()->firstOrFail();

        $this->expectException(\LogicException::class);
        $event->forceFill(['reason' => 'rewritten'])->save();
    }

    public function test_audit_rows_cannot_be_deleted(): void
    {
        $author = $this->command300Member();
        $service = app(CommandCommunicationService::class);
        $post = $service->post($author, 'command', null, 'Audited', null, $this->submissionId());
        $service->delete($author, $post, 'Audit me');

        $this->expectException(\LogicException::class);
        CommandCommunicationAuditEvent::query()->firstOrFail()->delete();
    }

    public function test_pinning_in_the_300_channel_is_open_to_300_readers_and_audited(): void
    {
        $member = $this->command300Member('Pinner');
        $id = $this->postVia($member, self::C300, 'Important');
        $post = CommandCommunicationPost::query()->where('public_id', $id)->firstOrFail();

        $this->actAs($this->command300Member('Another Pinner'));
        $this->postJson($this->commsBase(self::C300).'/posts/'.$id.'/pin', ['pinned' => true])->assertOk()->assertJsonPath('data.pinned', true);
        $this->postJson($this->commsBase(self::C300).'/posts/'.$id.'/pin', ['pinned' => true])->assertOk(); // same state: nothing new
        $this->getJson($this->commsBase(self::C300).'/posts')->assertJsonPath('pinned.0.id', $id);
        $this->postJson($this->commsBase(self::C300).'/posts/'.$id.'/pin', ['pinned' => false])->assertOk()->assertJsonPath('data.pinned', false);

        $this->assertNull($post->fresh()->pinned_at);
        $this->assertSame(['pinned', 'unpinned'], CommandCommunicationAuditEvent::query()->where('post_id', $post->id)->orderBy('id')->pluck('action')->all());
        $this->assertNull(CommandCommunicationAuditEvent::query()->where('post_id', $post->id)->value('reason'));
    }

    public function test_every_captain5_page_user_and_administrator_can_pin_in_the_captain5_channel(): void
    {
        $lieutenant = $this->captain5Viewer('Lieutenant Poster');
        $seatHolder = $this->officer('Captain', 'Captain 5 Seat');
        $this->seat($seatHolder, 'Captain 5', 'B Shift'); // off shift: no duty gating
        $id = $this->postVia($lieutenant, self::C5, 'Pin candidate');
        $post = CommandCommunicationPost::query()->where('public_id', $id)->firstOrFail();

        $this->actAs($this->newMember('Plain Firefighter'));
        $this->postJson($this->commsBase(self::C5).'/posts/'.$id.'/pin', ['pinned' => true])->assertForbidden();
        $this->assertNull($post->fresh()->pinned_at);

        $this->actAs($seatHolder);
        $this->postJson($this->commsBase(self::C5).'/posts/'.$id.'/pin', ['pinned' => true])->assertOk()->assertJsonPath('data.pinned', true);
        $this->assertSame($seatHolder->id, $post->fresh()->pinned_by_user_id);
        $this->actAs($lieutenant);
        $this->getJson($this->commsBase(self::C5).'/posts')->assertJsonPath('pinned.0.id', $id);

        $administrator = $this->communicationsViewer('Admin Pinner');
        $this->actAs($administrator);
        $this->postJson($this->commsBase(self::C5).'/posts/'.$id.'/pin', ['pinned' => false])->assertOk();
        $this->assertSame(['pinned', 'unpinned'], CommandCommunicationAuditEvent::query()->where('post_id', $post->id)->orderBy('id')->pluck('action')->all());
        $this->assertSame([$seatHolder->id, $administrator->id], CommandCommunicationAuditEvent::query()->where('post_id', $post->id)->orderBy('id')->pluck('actor_user_id')->all());
    }

    public function test_pinning_respects_channel_visibility_replies_and_removed_posts(): void
    {
        $seatHolder = $this->officer('Captain', 'Captain 5 Seat');
        $this->seat($seatHolder, 'Captain 5', 'A Shift');
        $only300 = $this->postVia($this->command300Member(), self::C300, 'Only 300');
        $both = $this->adminPost($this->superAdmin(), 'both', 'For both');
        $reply = $this->postVia($seatHolder, self::C5, 'A reply', $both->public_id);

        $this->actAs($seatHolder);
        $this->postJson($this->commsBase(self::C5).'/posts/'.$only300.'/pin', ['pinned' => true])->assertNotFound();
        $this->postJson($this->commsBase(self::C5).'/posts/'.$reply.'/pin', ['pinned' => true])->assertNotFound();

        // A pinned Both post is the same record in both feeds.
        $this->postJson($this->commsBase(self::C5).'/posts/'.$both->public_id.'/pin', ['pinned' => true])->assertOk();
        $this->actAs($this->bothChannelsMember());
        $this->getJson($this->commsBase(self::C300).'/posts')->assertJsonPath('pinned.0.id', $both->public_id);
        $this->getJson($this->commsBase(self::C5).'/posts')->assertJsonPath('pinned.0.id', $both->public_id);

        // A removed post cannot be pinned.
        $gone = $this->postVia($seatHolder, self::C5, 'Soon gone');
        $this->actAs($seatHolder);
        $this->deleteJson($this->commsBase(self::C5).'/posts/'.$gone, ['reason' => 'Changed my mind'])->assertOk();
        $this->postJson($this->commsBase(self::C5).'/posts/'.$gone.'/pin', ['pinned' => true])->assertUnprocessable();
    }
}
