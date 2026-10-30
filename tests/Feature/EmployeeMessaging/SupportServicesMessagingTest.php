<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeMessaging;

use App\Filament\Admin\Pages\EmployeeMessages;
use App\Filament\Employee\Pages\MyMessages;
use App\Filament\Widgets\EmployeeMessagesWidget;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Services\EmployeeMessaging\EmployeeMessageReason;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Message Support Services (docs/employee-logistics-messaging.md): the rename, the structured
 * reason (including "Bunker gear cleaning request"), the admin-home card and the end-to-end
 * member -> admin -> member flow.
 */
final class SupportServicesMessagingTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        RateLimiter::clear('employee-logistics-message:1');
        Filament::setCurrentPanel(Filament::getPanel('employee'));
    }

    // ------------------------------------------------------------ reasons: service

    public function test_a_bunker_gear_request_persists_its_reason_and_titles_the_conversation(): void
    {
        $member = $this->messagingMember('Gia Gear');
        $message = $this->service()->startThread($member, 'Please clean my coat and pants.', null, $this->rid(), EmployeeMessageReason::BUNKER_GEAR_CLEANING);
        $thread = $this->storedThread($message);

        $this->assertSame('bunker_gear_cleaning', $thread->reason);
        $this->assertSame('Bunker gear cleaning request', $thread->title());
        $this->assertSame('Bunker gear cleaning request', $thread->reasonTag());
        $this->assertSame('Sent, awaiting reply', $thread->memberStatus());
        $this->assertSame('Awaiting reply', $thread->supportStatus());
    }

    public function test_a_subject_wins_the_title_and_the_general_reason_has_no_tag(): void
    {
        $member = $this->messagingMember();
        $with = $this->storedThread($this->service()->startThread($member, 'Hello', 'My boots', $this->rid(), EmployeeMessageReason::BUNKER_GEAR_CLEANING));
        $general = $this->storedThread($this->service()->startThread($member, 'Hello again', null, $this->rid(), EmployeeMessageReason::GENERAL));
        $legacy = $this->storedThread($this->memberSends($member, 'Old style, no reason'));

        $this->assertSame('My boots', $with->title());
        $this->assertSame('General question', $general->title());
        $this->assertNull($general->reasonTag());
        $this->assertNull($legacy->reason, 'conversations started without a reason stay null');
        $this->assertSame('Message to Support Services', $legacy->title());
    }

    public function test_an_unknown_reason_is_refused_and_a_blank_one_means_none(): void
    {
        $member = $this->messagingMember();

        $this->assertInvalid(fn () => $this->service()->startThread($member, 'Hi', null, $this->rid(), 'free_text_reason'), 'reason');
        $this->assertSame(0, EmployeeLogisticsThread::query()->count(), 'a refused send creates nothing');

        $blank = $this->service()->startThread($member, 'Hi', null, $this->rid(), '  ');
        $this->assertNull($this->storedThread($blank)->reason);
    }

    public function test_the_reason_catalog_keeps_every_choice_and_the_new_one(): void
    {
        $this->assertSame(
            ['general', 'equipment', 'uniforms', 'supplies', 'bunker_gear_cleaning'],
            EmployeeMessageReason::keys(),
        );
        $this->assertSame('Bunker gear cleaning request', EmployeeMessageReason::label('bunker_gear_cleaning'));
        $this->assertNull(EmployeeMessageReason::label('nope'));
    }

    public function test_a_retried_bunker_gear_send_creates_one_conversation(): void
    {
        $member = $this->messagingMember();
        $requestId = $this->rid();

        $first = $this->service()->startThread($member, 'Clean my gear', null, $requestId, EmployeeMessageReason::BUNKER_GEAR_CLEANING);
        $retry = $this->service()->startThread($member, 'Clean my gear', null, $requestId, EmployeeMessageReason::BUNKER_GEAR_CLEANING);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, EmployeeLogisticsThread::query()->count());
    }

    // ------------------------------------------------------------ reasons: inbox filter and search

    public function test_the_inbox_filters_and_searches_by_reason_even_without_a_subject(): void
    {
        $viewer = $this->logisticsResponder();
        $this->service()->startThread($this->messagingMember('Ann'), 'Please launder it', null, $this->rid(), EmployeeMessageReason::BUNKER_GEAR_CLEANING);
        $this->service()->startThread($this->messagingMember('Bo'), 'Need gloves', null, $this->rid(), EmployeeMessageReason::SUPPLIES);
        $this->memberSends($this->messagingMember('Cy'), 'Old conversation with no reason');

        $byFilter = $this->service()->logisticsThreads($viewer, 'all', null, 20, EmployeeMessageReason::BUNKER_GEAR_CLEANING)['threads'];
        $this->assertSame(['Ann'], $byFilter->pluck('member_name')->all());

        $bySearch = $this->service()->logisticsThreads($viewer, 'all', 'bunker')['threads'];
        $this->assertSame(['Ann'], $bySearch->pluck('member_name')->all(), 'the body never says bunker; the reason label matches');

        $junk = $this->service()->logisticsThreads($viewer, 'all', null, 20, 'not_a_reason')['threads'];
        $this->assertCount(3, $junk, 'an unknown reason filter is ignored, never an error or an empty list');
    }

    public function test_the_admin_inbox_reason_filter_narrows_the_list(): void
    {
        $this->service()->startThread($this->messagingMember('Ann Gear'), 'Launder it', null, $this->rid(), EmployeeMessageReason::BUNKER_GEAR_CLEANING);
        $this->service()->startThread($this->messagingMember('Bo Shirt'), 'Need a shirt', null, $this->rid(), EmployeeMessageReason::UNIFORMS);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->logisticsResponder());

        Livewire::test(EmployeeMessages::class)
            ->assertSee('Ann Gear')->assertSee('Bo Shirt')
            ->set('reasonFilter', 'bunker_gear_cleaning')
            ->assertSee('Ann Gear')->assertDontSee('Bo Shirt')
            ->set('reasonFilter', 'bogus')
            ->assertSet('reasonFilter', '')
            ->assertSee('Bo Shirt');
    }

    // ------------------------------------------------------------ rename and member page

    public function test_the_user_facing_rename_is_applied_on_home_dashboard_and_my_messages(): void
    {
        $member = $this->messagingMember();

        $home = $this->actingAsCanonicalUser($member)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Message Support Services', $home);
        $this->assertStringNotContainsString('Message Logistics', $home);
        $this->assertStringContainsString('/employee/messages?compose=1', $home, 'the route is unchanged');

        $page = $this->actingAsCanonicalUser($member)->get('/employee/messages?compose=1')->assertOk()->getContent();
        $this->assertStringContainsString('New message to Support Services', $page);
        $this->assertStringContainsString('Send to Support Services', $page);
        $this->assertStringContainsString('Bunker gear cleaning request', $page, 'offered as a Reason');
        $this->assertStringNotContainsString('Send to Logistics', $page);

        $dashboard = $this->actingAsCanonicalUser($member)->get('/employee/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('Message Support Services', $dashboard);
        $this->assertStringNotContainsString('Message Logistics', $dashboard);
    }

    public function test_the_member_page_sends_a_reason_and_still_accepts_the_old_three_argument_call(): void
    {
        $member = $this->messagingMember();
        $this->actingAs($member);

        Livewire::test(MyMessages::class, ['composing' => true])
            ->call('startThread', 'Please clean my turnout gear', '', $this->rid(), 'bunker_gear_cleaning')
            ->assertReturned(['ok' => true]);
        // A page rendered by the previous release posts (body, subject, requestId) only.
        Livewire::test(MyMessages::class, ['composing' => true])
            ->call('startThread', 'Old client message', '', $this->rid())
            ->assertReturned(['ok' => true]);
        Livewire::test(MyMessages::class, ['composing' => true])
            ->call('startThread', 'Tampered reason', '', $this->rid(), '<script>')
            ->assertReturned(['ok' => false, 'message' => 'Choose one of the reasons in the list.']);

        $reasons = EmployeeLogisticsThread::query()->orderBy('id')->pluck('reason')->all();
        $this->assertSame(['bunker_gear_cleaning', null], $reasons);
    }

    public function test_the_member_sees_reason_status_and_the_support_services_reply(): void
    {
        $member = $this->messagingMember();
        $sent = $this->service()->startThread($member, 'Please clean my coat', null, $this->rid(), EmployeeMessageReason::BUNKER_GEAR_CLEANING);
        $this->actingAs($member);

        Livewire::test(MyMessages::class, ['threadId' => $sent->thread_id])
            ->assertSee('Reason: Bunker gear cleaning request')
            ->assertSee('Sent, awaiting reply');

        $this->logisticsReplies($this->logisticsResponder(), $sent, 'Bring it Monday.');
        Livewire::test(MyMessages::class, ['threadId' => $sent->thread_id])
            ->assertSee('Bring it Monday.')
            ->assertSee('Support Services')
            ->assertDontSee('Sent, awaiting reply');
    }

    // ------------------------------------------------------------ admin home card

    public function test_the_admin_home_card_shows_counts_sender_subject_reason_and_a_reply_link_to_that_conversation(): void
    {
        $member = $this->messagingMember('Gia Gear', ['employee_id' => 'EMP-0900', 'station' => 'Station 3']);
        $sent = $this->service()->startThread($member, 'Please clean my coat and pants', 'Turnout cleaning', $this->rid(), EmployeeMessageReason::BUNKER_GEAR_CLEANING);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->logisticsResponder());

        $card = Livewire::test(EmployeeMessagesWidget::class)
            ->assertSee('Support Services Messages')
            ->assertSee('1 awaiting reply')
            ->assertSee('1 unread')
            ->assertSee('Gia Gear')
            ->assertSee('Turnout cleaning')
            ->assertSee('Bunker gear cleaning request')
            ->assertSee('Station 3');

        $html = $card->html();
        $this->assertStringContainsString('data-emsg-open-link', $html);
        $this->assertStringContainsString(EmployeeMessages::getUrl(['thread' => $sent->thread_id]), html_entity_decode($html));
        $this->assertMatchesRegularExpression('/data-emsg-open-link[^>]*>\s*Reply\s*</', $html);
    }

    public function test_the_admin_home_card_has_a_contextual_empty_state(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->logisticsResponder());

        Livewire::test(EmployeeMessagesWidget::class)
            ->assertSee('No Support Services messages yet')
            ->assertSee('bunker gear cleaning request')
            ->assertDontSee('awaiting reply');
    }

    public function test_a_read_only_admin_gets_view_not_reply_and_an_unrelated_admin_gets_no_card(): void
    {
        $sent = $this->service()->startThread($this->messagingMember('Gia Gear'), 'Clean it', null, $this->rid(), EmployeeMessageReason::BUNKER_GEAR_CLEANING);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($this->logisticsViewer());
        $viewer = Livewire::test(EmployeeMessagesWidget::class)->html();
        $this->assertMatchesRegularExpression('/data-emsg-open-link[^>]*>\s*View\s*</', $viewer);
        $this->assertDoesNotMatchRegularExpression('/data-emsg-open-link[^>]*>\s*Reply\s*</', $viewer);

        $this->actingAs($this->unrelatedAdmin());
        $this->assertFalse(EmployeeMessagesWidget::canView());
        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringNotContainsString('Support Services Messages', $html);
        $this->assertStringNotContainsString('Gia Gear', $html);
        $this->get('/admin/employee-messages?thread='.$sent->thread_id)->assertForbidden();
    }

    // ------------------------------------------------------------ the whole flow, and its boundaries

    public function test_member_submits_admin_card_updates_admin_replies_in_that_conversation_member_sees_it(): void
    {
        $member = $this->messagingMember('Gia Gear');
        $other = $this->messagingMember('Oz Other');
        $responder = $this->logisticsResponder();

        // 1. The member submits a bunker gear request.
        $this->actingAs($member);
        $sent = Livewire::test(MyMessages::class, ['composing' => true])
            ->call('startThread', 'Please clean my turnout coat', '', $this->rid(), 'bunker_gear_cleaning')
            ->assertReturned(['ok' => true]);
        $thread = EmployeeLogisticsThread::query()->sole();

        // 2. The admin card counts it and links to exactly that conversation.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($responder);
        $card = Livewire::test(EmployeeMessagesWidget::class)->assertSee('1 awaiting reply')->assertSee('Gia Gear');
        $this->assertStringContainsString('thread='.$thread->id, html_entity_decode($card->html()));

        // 3. The deep link opens that conversation (and a refresh keeps it); the admin replies.
        $this->get('/admin/employee-messages?thread='.$thread->id)->assertOk()->assertSee('Please clean my turnout coat');
        $page = Livewire::test(EmployeeMessages::class, ['threadId' => $thread->id])->assertSee('Please clean my turnout coat');
        $page->call('sendReply', 'Drop it at Station 1 Monday.', $this->rid(), $thread->id)->assertReturned(['ok' => true]);
        $this->assertSame('Replied', $this->storedThread($thread)->supportStatus());
        Livewire::test(EmployeeMessagesWidget::class)->assertDontSee('1 awaiting reply');

        // 4. The originating member sees the reply; another member cannot reach the conversation.
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $this->actingAs($member);
        Livewire::test(MyMessages::class, ['threadId' => $thread->id])->assertSee('Drop it at Station 1 Monday.');

        $this->actingAs($other);
        Livewire::test(MyMessages::class, ['threadId' => $thread->id])
            ->assertSee('Conversation not found')
            ->assertDontSee('Drop it at Station 1 Monday.')
            ->assertDontSee('Please clean my turnout coat');
        $this->assertFailsWith(\Illuminate\Database\Eloquent\ModelNotFoundException::class, fn () => $this->service()->memberThread($other, $thread->id));
        $this->assertFailsWith(AuthorizationException::class, fn () => $this->service()->replyAsLogistics($other, $thread->id, 'sneaky', $this->rid()));
    }

    // ------------------------------------------------------------ stylesheet regression

    public function test_the_stylesheet_never_uses_a_hub_colour_token_without_rgb(): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(base_path('resources/css/filament/employee-messages.css')));

        // --hub2-* colours are RGB channel triplets: var(--hub2-x, #fallback) is an invalid colour.
        $this->assertDoesNotMatchRegularExpression('/(?<!rgb\()var\(--hub2-(n-\d+|action|action-tint|action-hover|focus|danger|success|warning)[^)]*\)/', $css);
        $this->assertDoesNotMatchRegularExpression('/var\(--hub2-[a-z0-9-]+,\s*#/', $css);
        $this->assertStringContainsString('.emsg-bubble', $css);
    }
}
