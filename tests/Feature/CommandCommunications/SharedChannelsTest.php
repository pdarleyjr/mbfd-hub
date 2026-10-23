<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCommunications;

use App\Models\CommandCommunicationChannelRead;
use App\Models\CommandCommunicationPost;
use App\Models\CommandCommunicationReadState;
use App\Models\User;
use App\Services\Captain5\Captain5Notifier;
use App\Services\CommandCommunications\CommandCommunicationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\CommandCommunications\Concerns\BuildsSharedCommunicationsWorld;
use Tests\TestCase;

/**
 * One record per post with an audience, shown in every feed whose channel it includes, with
 * channel isolation, per-channel read state and the on-duty-only administrator notification.
 */
final class SharedChannelsTest extends TestCase
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

    public function test_a_post_lives_in_the_feeds_its_audience_names_and_a_both_post_is_one_row(): void
    {
        $member300 = $this->command300Member();
        $lieutenant = $this->captain5Viewer();
        $chief = $this->bothChannelsMember();
        $admin = $this->superAdmin();

        $only300 = $this->postVia($member300, self::C300, 'Staffing at 300');
        $only5 = $this->postVia($lieutenant, self::C5, 'Medical supply question');
        $both = $this->adminPost($admin, 'both', 'Everyone please read');

        $this->assertSame('command_300', CommandCommunicationPost::query()->where('public_id', $only300)->value('audience'));
        $this->assertSame('captain_5', CommandCommunicationPost::query()->where('public_id', $only5)->value('audience'));
        $this->assertSame('command', CommandCommunicationPost::query()->where('public_id', $only300)->value('author_context'));
        $this->assertSame('captain5', CommandCommunicationPost::query()->where('public_id', $only5)->value('author_context'));
        $this->assertSame('administration', $both->author_context);

        $this->assertEqualsCanonicalizing([$both->public_id, $only300], $this->feedIds($member300, self::C300));
        $this->assertEqualsCanonicalizing([$both->public_id, $only5], $this->feedIds($lieutenant, self::C5));
        $this->assertEqualsCanonicalizing([$both->public_id, $only300], $this->feedIds($chief, self::C300));
        $this->assertEqualsCanonicalizing([$both->public_id, $only5], $this->feedIds($chief, self::C5));

        // One shared record, never a copy per feed.
        $this->assertSame(3, CommandCommunicationPost::query()->count());
        $this->assertSame(1, CommandCommunicationPost::query()->where('audience', 'both')->count());
    }

    public function test_replies_inherit_the_parent_audience_and_appear_exactly_where_the_parent_does(): void
    {
        $member300 = $this->command300Member();
        $lieutenant = $this->captain5Viewer();
        $admin = $this->superAdmin();
        $both = $this->adminPost($admin, 'both', 'Shared thread');
        $only300 = $this->postVia($member300, self::C300, 'Private to 300');
        $only5 = $this->postVia($lieutenant, self::C5, 'Private to Captain 5');

        // A reply from either side to a Both post is itself Both.
        $fromLieutenant = $this->postVia($lieutenant, self::C5, 'Lieutenant reply', $both->public_id);
        $from300 = $this->postVia($member300, self::C300, '300 reply', $both->public_id);
        $this->assertSame(['both', 'both'], CommandCommunicationPost::query()->whereIn('public_id', [$fromLieutenant, $from300])->orderBy('id')->pluck('audience')->all());
        $this->assertSame($both->id, CommandCommunicationPost::query()->where('public_id', $from300)->value('parent_id'));

        foreach ([[$member300, self::C300], [$lieutenant, self::C5]] as [$user, $channel]) {
            $this->actAs($user);
            $thread = collect($this->getJson($this->commsBase($channel).'/posts')->json('data'))->firstWhere('id', $both->public_id);
            $this->assertEqualsCanonicalizing([$fromLieutenant, $from300], array_column($thread['replies'], 'id'));
        }

        // A reply to a post the author cannot see is refused, and nothing is stored.
        $before = CommandCommunicationPost::query()->count();
        $this->actAs($lieutenant);
        $this->postJson($this->commsBase(self::C5).'/posts', ['body' => 'sneaky', 'parent_id' => $only300, 'client_submission_id' => $this->submissionId()])->assertUnprocessable();
        $this->actAs($member300);
        $this->postJson($this->commsBase(self::C300).'/posts', ['body' => 'sneaky', 'parent_id' => $only5, 'client_submission_id' => $this->submissionId()])->assertUnprocessable();
        $this->assertSame($before, CommandCommunicationPost::query()->count());

        // A Captain 5 member's reply on a Captain 5 only post stays Captain 5 only.
        $reply = $this->postVia($lieutenant, self::C5, 'Follow-up', $only5);
        $this->assertSame('captain_5', CommandCommunicationPost::query()->where('public_id', $reply)->value('audience'));
        $this->assertNotContains($only5, $this->feedIds($member300, self::C300));
    }

    public function test_channels_are_isolated_by_who_the_viewer_is(): void
    {
        $post300 = $this->postVia($this->command300Member(), self::C300, 'A 300 message');
        $post5 = $this->postVia($this->captain5Viewer(), self::C5, 'A Captain 5 message');

        $firefighter = $this->outsider();
        $this->actAs($firefighter);
        foreach ([self::C300, self::C5] as $channel) {
            $base = $this->commsBase($channel);
            $this->getJson($base.'/posts')->assertForbidden();
            $this->getJson($base.'/summary')->assertForbidden();
            $this->postJson($base.'/posts', ['body' => 'hi', 'client_submission_id' => $this->submissionId()])->assertForbidden();
            $this->postJson($base.'/posts/'.$post300.'/pin', ['pinned' => true])->assertForbidden();
            $this->postJson($base.'/read')->assertForbidden();
            $this->deleteJson($base.'/posts/'.$post5, ['reason' => 'not mine to remove'])->assertForbidden();
        }

        // A Lieutenant reads Captain 5 but not the 300 conversation.
        $lieutenant = $this->captain5Viewer();
        $this->actAs($lieutenant);
        $this->getJson($this->commsBase(self::C5).'/posts')->assertOk()->assertJsonPath('data.0.id', $post5);
        $this->getJson($this->commsBase(self::C300).'/posts')->assertForbidden();
        $this->postJson($this->commsBase(self::C300).'/posts', ['body' => 'x', 'client_submission_id' => $this->submissionId()])->assertForbidden();

        // A 300 member reads the 300 conversation but not Captain 5's.
        $this->actAs($this->command300Member('Another 300 Member'));
        $this->getJson($this->commsBase(self::C300).'/posts')->assertOk();
        $this->getJson($this->commsBase(self::C5).'/posts')->assertForbidden();

        // A Division Chief reads both.
        $this->actAs($this->bothChannelsMember());
        $this->getJson($this->commsBase(self::C300).'/posts')->assertOk()->assertJsonPath('data.0.id', $post300);
        $this->getJson($this->commsBase(self::C5).'/posts')->assertOk()->assertJsonPath('data.0.id', $post5);

        $this->assertSame(2, CommandCommunicationPost::query()->count(), 'Refused requests stored nothing');
    }

    public function test_guests_receive_401_on_the_captain5_endpoints(): void
    {
        $base = $this->commsBase(self::C5);
        $this->getJson($base.'/posts')->assertUnauthorized();
        $this->getJson($base.'/summary')->assertUnauthorized();
        $this->postJson($base.'/posts', ['body' => 'x'])->assertUnauthorized();
        $this->postJson($base.'/posts/01ARZ3NDEKTSV4RRFFQ69G5FAV/pin', ['pinned' => true])->assertUnauthorized();
        $this->deleteJson($base.'/posts/01ARZ3NDEKTSV4RRFFQ69G5FAV', ['reason' => 'because'])->assertUnauthorized();
        $this->postJson($base.'/read')->assertUnauthorized();
    }

    public function test_the_kill_switch_closes_the_captain5_channel_but_not_the_300_one(): void
    {
        $chief = $this->bothChannelsMember();
        $admin = $this->superAdmin();
        $this->adminPost($admin, 'both', 'Before the switch');
        config(['captain5.communications_enabled' => false]);

        $this->actAs($chief);
        $this->getJson($this->commsBase(self::C5).'/posts')->assertForbidden();
        $this->getJson($this->commsBase(self::C5).'/summary')->assertForbidden();
        $this->getJson($this->commsBase(self::C300).'/posts')->assertOk()->assertJsonCount(1, 'data');

        foreach (['captain_5', 'both'] as $audience) {
            try {
                $this->adminPost($admin, $audience, 'While closed');
                $this->fail("A {$audience} post must be refused while the channel is off");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('audience', $exception->errors());
            }
        }
        $this->assertSame(1, CommandCommunicationPost::query()->count());
        $this->adminPost($admin, 'command_300', 'Still works');
        $this->assertSame(2, CommandCommunicationPost::query()->count());
    }

    public function test_only_administrators_choose_an_audience_and_it_is_checked_against_what_they_can_read(): void
    {
        $service = app(CommandCommunicationService::class);
        $chief = $this->bothChannelsMember();
        $thrown = [];

        foreach ([
            'a 300 post cannot pick another audience' => fn () => $service->post($chief, 'command', null, 'x', null, $this->submissionId(), [], 'captain_5'),
            'only administrators use the administration context' => fn () => $service->post($chief, 'administration', null, 'x', null, $this->submissionId()),
            'an unknown audience is refused' => fn () => $service->post($this->superAdmin(), 'administration', null, 'x', null, $this->submissionId(), [], 'everyone'),
        ] as $label => $attempt) {
            try {
                $attempt();
            } catch (\InvalidArgumentException|AuthorizationException|ValidationException $exception) {
                $thrown[$label] = $exception::class;
            }
        }

        $this->assertSame([
            'a 300 post cannot pick another audience' => \InvalidArgumentException::class,
            'only administrators use the administration context' => AuthorizationException::class,
            'an unknown audience is refused' => ValidationException::class,
        ], $thrown);
        $this->assertSame(0, CommandCommunicationPost::query()->count());
    }

    public function test_attachments_are_served_per_audience_and_never_after_removal(): void
    {
        $admin = $this->superAdmin();
        $member300 = $this->command300Member();
        $lieutenant = $this->captain5Viewer();
        $chief = $this->bothChannelsMember();
        $firefighter = $this->outsider();

        $forBoth = $this->adminPost($admin, 'both', 'Both file', [$this->pdfUpload('both.pdf')]);
        $for5 = $this->adminPost($admin, 'captain_5', 'Captain 5 file', [$this->pdfUpload('c5.pdf')]);
        $for300 = $this->adminPost($admin, 'command_300', '300 file', [$this->pdfUpload('c300.pdf')]);
        $url = static fn (CommandCommunicationPost $post): string => '/command-communications/attachments/'.$post->attachments()->value('public_id');

        $matrix = [
            // viewer => [both, captain_5 only, 300 only]
            // A reader of another channel gets 404 (no existence oracle); someone who reads no channel at all gets 403.
            'member300' => [$member300, [200, 404, 200]],
            'lieutenant' => [$lieutenant, [200, 200, 404]],
            'chief' => [$chief, [200, 200, 200]],
            'firefighter' => [$firefighter, [403, 403, 403]],
        ];
        foreach ($matrix as $label => [$user, $expected]) {
            $this->actAs($user);
            foreach ([$forBoth, $for5, $for300] as $index => $post) {
                $this->get($url($post))->assertStatus($expected[$index], "{$label} downloading post {$index}");
            }
        }

        $this->actAs($member300);
        $response = $this->get($url($forBoth))->assertOk();
        $this->assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // Once the post is removed nobody is served the file: not the reader, not the author, not an administrator.
        app(CommandCommunicationService::class)->delete($admin, $forBoth->refresh(), 'Sent to the wrong people', 'all');
        foreach ([$member300, $lieutenant, $chief, $admin] as $user) {
            $this->actAs($user);
            $this->get($url($forBoth))->assertNotFound();
        }
        $this->actAs($firefighter);
        $this->get($url($forBoth))->assertForbidden();
        $this->actAs($lieutenant);
        $this->get($url($for5))->assertOk();
    }

    public function test_guests_cannot_download_attachments(): void
    {
        $post = $this->adminPost($this->superAdmin(), 'both', 'File', [$this->pdfUpload()]);

        $this->getJson('/command-communications/attachments/'.$post->attachments()->value('public_id'))->assertUnauthorized();
    }

    public function test_unread_counts_and_mark_read_are_per_channel(): void
    {
        $chief = $this->bothChannelsMember();
        $lieutenant = $this->captain5Viewer();
        $member300 = $this->command300Member();
        $admin = $this->superAdmin();
        $this->adminPost($admin, 'both', 'For everyone');
        $this->adminPost($admin, 'captain_5', 'For Captain 5');
        $this->adminPost($admin, 'command_300', 'For 300');
        $this->postVia($member300, self::C300, 'A 300 member says hi');

        $this->actAs($chief);
        $this->getJson($this->commsBase(self::C300).'/summary')->assertOk()->assertJsonPath('unread_count', 3);
        $this->getJson($this->commsBase(self::C5).'/summary')->assertOk()->assertJsonPath('unread_count', 2);
        $this->assertSame(3, $this->getJson($this->commsBase(self::C300).'/posts')->json('unread_count'));
        $this->assertSame(2, $this->getJson($this->commsBase(self::C5).'/posts')->json('unread_count'));

        // The 300 channel's own table is untouched by Captain 5 reads, and vice versa.
        $this->postJson($this->commsBase(self::C5).'/read')->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertSame(0, CommandCommunicationReadState::query()->where('user_id', $chief->id)->count());
        $this->assertSame(1, CommandCommunicationChannelRead::query()->where('user_id', $chief->id)->where('channel', 'captain_5')->count());
        $this->getJson($this->commsBase(self::C300).'/summary')->assertJsonPath('unread_count', 3);

        $this->postJson($this->commsBase(self::C300).'/read')->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertSame(1, CommandCommunicationReadState::query()->where('user_id', $chief->id)->count());
        $this->assertSame(0, CommandCommunicationChannelRead::query()->where('user_id', $chief->id)->where('channel', 'command_300')->count(), 'The 300 channel never uses the new table');

        // Marking read again moves the marker; the (user, channel) pair stays unique.
        $this->travel(5)->minutes();
        $this->postJson($this->commsBase(self::C5).'/read')->assertOk();
        $this->assertSame(1, CommandCommunicationChannelRead::query()->where('user_id', $chief->id)->count());

        // Captain 5 viewers only count what Captain 5 can see.
        $this->actAs($lieutenant);
        $this->getJson($this->commsBase(self::C5).'/summary')->assertJsonPath('unread_count', 2);
        $this->assertSame(0, CommandCommunicationChannelRead::query()->where('user_id', $lieutenant->id)->count(), 'Reading the summary does not mark anything read');

        // One person's own posts are never unread to them: the 300 member sees the two admin posts only.
        $this->actAs($member300);
        $this->getJson($this->commsBase(self::C300).'/summary')->assertJsonPath('unread_count', 2);
    }

    public function test_only_the_on_duty_captain5_is_notified_when_an_administrator_posts_to_captain5(): void
    {
        $onDuty = $this->officer('Captain', 'On Duty Captain 5');
        $this->seat($onDuty, 'Captain 5', 'A Shift'); // the world's clock is an A day
        $offDuty = $this->officer('Captain', 'Off Duty Captain 5');
        $this->seat($offDuty, 'Captain 5', 'B Shift');
        $lieutenant = $this->captain5Viewer();
        $chief = $this->bothChannelsMember();
        $member300 = $this->command300Member();
        $admin = $this->superAdmin();

        $captain5Post = $this->adminPost($admin, 'captain_5', 'Please acknowledge', [], null);
        $bothPost = $this->adminPost($admin, 'both', 'Shared notice');
        $only300 = $this->adminPost($admin, 'command_300', 'Just 300');
        $fromMember = $this->postVia($lieutenant, self::C5, 'A Captain 5 member posts');
        $fromMember300 = $this->postVia($member300, self::C300, 'A 300 member posts');

        $this->assertLedger('captain5:communication:'.$captain5Post->public_id, [$onDuty]);
        $this->assertLedger('captain5:communication:'.$bothPost->public_id, [$onDuty]);
        $this->assertLedger('captain5:communication:'.$only300->public_id, []);
        $this->assertLedger('captain5:communication:'.$fromMember, []);
        $this->assertLedger('captain5:communication:'.$fromMember300, []);
        $this->assertSame('captain5_communication', DB::table('inspection_notification_deliveries')->where('event_key', 'captain5:communication:'.$captain5Post->public_id)->value('notification_type'));

        // Nobody else eligible was alerted, only the on-duty officer's ledger row exists.
        foreach ([$offDuty, $lieutenant, $chief, $member300] as $other) {
            $this->assertSame(0, DB::table('inspection_notification_deliveries')->where('recipient_user_id', $other->id)->count(), "{$other->name} is not alerted");
        }

        // An administrator's reply on a Captain 5 thread reaches the on-duty officer too; a replay is a no-op.
        $reply = $this->adminPost($admin, 'command_300', 'Reply', [], $captain5Post->public_id);
        $this->assertSame('captain_5', $reply->audience);
        $this->assertLedger('captain5:communication:'.$reply->public_id, [$onDuty]);
        $this->assertSame(1, DB::table('inspection_notification_deliveries')->where('event_key', 'captain5:communication:'.$captain5Post->public_id)->count());
    }

    public function test_the_retry_command_can_rebuild_a_communication_event_from_its_post(): void
    {
        $onDuty = $this->officer('Captain', 'On Duty Captain 5');
        $this->seat($onDuty, 'Captain 5', 'A Shift');
        $admin = $this->superAdmin();
        $post = $this->adminPost($admin, 'captain_5', 'Rebuild me');

        $notifier = app(Captain5Notifier::class);
        $key = 'captain5:communication:'.$post->public_id;
        $this->assertTrue($notifier->redeliver($key, Captain5Notifier::KIND_COMMUNICATION, $onDuty->id), 'The event is rebuilt from the post and delivered to the ledger recipient');
        $this->assertFalse($notifier->redeliver($key, Captain5Notifier::KIND_COMMUNICATION, $this->captain5Viewer()->id), 'Only the original recipient is replayed');

        app(CommandCommunicationService::class)->delete($admin, $post->refresh(), 'Posted by mistake', 'all');
        $this->assertFalse($notifier->redeliver($key, Captain5Notifier::KIND_COMMUNICATION, $onDuty->id), 'A removed post is not replayed');
        $this->assertFalse($notifier->redeliver('captain5:communication:01ARZ3NDEKTSV4RRFFQ69G5FAV', Captain5Notifier::KIND_COMMUNICATION, $onDuty->id));
    }

    public function test_existing_posts_default_to_the_300_audience(): void
    {
        $post = CommandCommunicationPost::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(), 'author_user_id' => $this->superAdmin()->id,
            'author_context' => 'command', 'body' => 'Legacy row without an audience', 'client_submission_id' => 'legacy-00001',
        ]);

        $this->assertSame('command_300', $post->fresh()->audience);
        $this->assertContains($post->public_id, $this->feedIds($this->command300Member(), self::C300));
        $this->assertNotContains($post->public_id, $this->feedIds($this->captain5Viewer(), self::C5));
    }

    /** @param  list<User>  $expected */
    private function assertLedger(string $eventKey, array $expected): void
    {
        $actual = DB::table('inspection_notification_deliveries')->where('event_key', $eventKey)->pluck('recipient_user_id')
            ->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $wanted = collect($expected)->map(fn (User $user): int => (int) $user->id)->sort()->values()->all();
        $this->assertSame($wanted, $actual, "Recipients of {$eventKey}");
    }
}
