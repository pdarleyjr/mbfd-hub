<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeMessaging;

use App\Filament\Admin\Pages\EmployeeMessages;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\EmployeeMessagesWidget;
use App\Filament\Widgets\StationOperationsHubWidget;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Logistics side: Admin Panel inbox page, Logistics Dashboard card, navigation badge and the
 * authorization that must agree across all of them (docs/employee-logistics-messaging.md sections 1 and 3).
 */
final class EmployeeLogisticsAdminInboxTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        RateLimiter::clear('employee-logistics-message:1');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // ------------------------------------------------------------ badge, poll, open

    public function test_the_badge_counts_unread_conversations_and_a_poll_never_marks_anything_read(): void
    {
        $first = $this->memberSends($this->messagingMember('Ann Jones'), 'Boots arrived damaged', 'Boots');
        $this->memberSends($this->messagingMember('Bo Smith'), 'Need a size swap');
        $this->actingAs($this->logisticsResponder());

        $this->assertTrue(EmployeeMessages::canAccess());
        $this->assertSame('2', EmployeeMessages::getNavigationBadge());
        $this->assertSame('info', EmployeeMessages::getNavigationBadgeColor());
        $this->assertSame('Unread Support Services messages', EmployeeMessages::getNavigationBadgeTooltip());

        Livewire::test(EmployeeMessages::class)
            ->assertSee('Ann Jones')
            ->assertSee('Bo Smith')
            ->assertSee('Awaiting reply')
            ->assertSee('Select a conversation')
            ->call('poll')
            ->assertSee('Ann Jones');

        $this->assertNull($this->storedThread($first)->logistics_read_message_id);
        $this->assertSame('2', EmployeeMessages::getNavigationBadge());
    }

    public function test_opening_a_conversation_marks_it_read_and_clears_only_its_unread_state(): void
    {
        $first = $this->memberSends($this->messagingMember('Ann Jones'), 'Boots arrived damaged');
        $second = $this->memberSends($this->messagingMember('Bo Smith'), 'Need a size swap');
        $this->actingAs($this->logisticsResponder());

        Livewire::test(EmployeeMessages::class)
            ->call('openThread', $first->thread_id)
            ->assertSee('Boots arrived damaged')
            ->assertSee('Reply to Ann Jones');

        $this->assertSame($first->id, $this->storedThread($first)->logistics_read_message_id);
        $this->assertNull($this->storedThread($second)->logistics_read_message_id);
        $this->assertSame('1', EmployeeMessages::getNavigationBadge());
    }

    /**
     * A thread left open on a station PC or in a background tab must not swallow a new member
     * message for the whole team: polls (and search/filter re-renders) never move the shared marker.
     */
    public function test_a_poll_while_a_conversation_is_open_shows_a_new_message_but_leaves_it_unread(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member, 'First question');
        $this->actingAs($this->logisticsResponder());

        $page = Livewire::test(EmployeeMessages::class)->call('openThread', $first->thread_id);
        $second = $this->memberReplies($member, $first, 'A second question');

        $page->call('poll')->assertSee('A second question');
        $page->set('search', 'question');
        $page->call('setFilter', 'unread');
        $this->assertSame($first->id, $this->storedThread($first)->logistics_read_message_id);
        $this->assertSame('1', EmployeeMessages::getNavigationBadge());

        // Opening it again is an explicit read.
        $page->call('openThread', $first->thread_id);
        $this->assertSame($second->id, $this->storedThread($first)->logistics_read_message_id);
    }

    public function test_a_reply_is_refused_when_the_page_switched_conversations_meanwhile(): void
    {
        $a = $this->memberSends($this->messagingMember('Ann Jones'), 'About my boots');
        $b = $this->memberSends($this->messagingMember('Bo Smith'), 'About my gloves');
        $this->actingAs($this->logisticsResponder());

        $page = Livewire::test(EmployeeMessages::class)->call('openThread', $b->thread_id);
        $page->call('sendReply', 'Reply typed for Ann', $this->rid(), $a->thread_id)
            ->assertReturned(['ok' => false, 'message' => 'You switched conversations while sending. Your message was not sent and is still here.']);

        $this->assertSame(1, $this->storedThread($a)->message_count);
        $this->assertSame(1, $this->storedThread($b)->message_count);
    }

    // ------------------------------------------------------------ replying

    public function test_a_reply_succeeds_appears_in_the_thread_and_a_blank_reply_is_refused_with_a_message(): void
    {
        $first = $this->memberSends($this->messagingMember('Ann Jones'), 'Boots arrived damaged');
        $this->actingAs($this->logisticsResponder());

        $page = Livewire::test(EmployeeMessages::class)->call('openThread', $first->thread_id);

        $page->call('sendReply', 'We will replace them.', $this->rid())
            ->assertReturned(['ok' => true])
            ->assertSee('We will replace them.');
        $thread = $this->storedThread($first);
        $this->assertSame(2, $thread->message_count);
        $this->assertSame('logistics', $thread->last_message_side);

        $page->call('sendReply', '   ', $this->rid())
            ->assertReturned(['ok' => false, 'message' => 'Type a message before sending.']);
        $this->assertSame(2, $this->storedThread($first)->message_count);
    }

    public function test_replying_without_an_open_conversation_is_a_friendly_failure(): void
    {
        $this->actingAs($this->logisticsResponder());

        Livewire::test(EmployeeMessages::class)
            ->call('sendReply', 'Hello', $this->rid())
            ->assertReturned(['ok' => false, 'message' => 'This conversation is no longer available.']);
    }

    // ------------------------------------------------------------ filter, search, show more

    public function test_filter_search_and_show_more(): void
    {
        $answered = $this->memberSends($this->messagingMember('Ann Jones'), 'Boots arrived damaged');
        $this->logisticsReplies($this->logisticsResponder(), $answered, 'On it.');
        $this->memberSends($this->messagingMember('Bo Smith'), 'Need a size swap');
        $this->actingAs($this->logisticsResponder());

        $page = Livewire::test(EmployeeMessages::class);

        $page->call('setFilter', 'awaiting')->assertSet('filter', 'awaiting')->assertSee('Bo Smith')->assertDontSee('Ann Jones');
        $page->call('setFilter', 'unread')->assertSee('Bo Smith');
        $page->set('filter', 'bogus')->assertSet('filter', 'all');
        $page->call('setFilter', 'all')->assertSee('Ann Jones')->assertSee('Bo Smith');

        $page->set('search', 'size swap')->assertSee('Bo Smith')->assertDontSee('Ann Jones');
        $page->set('search', 'zzz-no-such-thing')->assertSee('No conversations match');
        $page->set('search', '')->assertSee('Ann Jones');

        $page->assertSet('limit', 20)->call('showMore')->assertSet('limit', 40);
        $page->call('setFilter', 'unread')->assertSet('limit', 20);
    }

    public function test_show_more_is_offered_only_when_there_are_more_conversations(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->memberSends($this->messagingMember('Member '.$i), 'Message '.$i);
        }
        $this->actingAs($this->logisticsResponder());

        $page = Livewire::test(EmployeeMessages::class)->assertSee('Show more')->assertDontSee('Member 1<');
        $page->call('showMore')->assertDontSee('Show more');
    }

    // ------------------------------------------------------------ ?thread=

    public function test_a_valid_thread_parameter_opens_and_marks_the_conversation_read(): void
    {
        $message = $this->memberSends($this->messagingMember('Ann Jones'), 'Boots arrived damaged');
        $this->actingAs($this->logisticsResponder());

        Livewire::withQueryParams(['thread' => $message->thread_id])->test(EmployeeMessages::class)
            ->assertSee('Boots arrived damaged')
            ->assertSet('threadId', $message->thread_id);

        $this->assertSame($message->id, $this->storedThread($message)->logistics_read_message_id);
    }

    public function test_a_stale_thread_parameter_shows_a_friendly_state_and_back_clears_it(): void
    {
        $this->actingAs($this->logisticsResponder());

        Livewire::withQueryParams(['thread' => '99999'])->test(EmployeeMessages::class)
            ->assertOk()
            ->assertSee('That conversation could not be found')
            ->call('closeThread')
            ->assertSet('threadId', null)
            ->assertSee('Select a conversation');
    }

    public function test_a_garbage_thread_parameter_falls_back_to_the_list_without_an_error(): void
    {
        $this->memberSends($this->messagingMember('Ann Jones'));
        $this->actingAs($this->logisticsResponder());

        Livewire::withQueryParams(['thread' => 'abc'])->test(EmployeeMessages::class)
            ->assertOk()
            ->assertSet('threadId', null)
            ->assertSee('Select a conversation')
            ->assertSee('Ann Jones');
    }

    // ------------------------------------------------------------ read-only viewer

    public function test_a_read_only_viewer_can_read_but_not_reply(): void
    {
        $message = $this->memberSends($this->messagingMember('Ann Jones'), 'Boots arrived damaged');
        $this->actingAs($this->logisticsViewer());

        $page = Livewire::test(EmployeeMessages::class)
            ->call('openThread', $message->thread_id)
            ->assertSee('Boots arrived damaged')
            ->assertSee('You can read Support Services messages but not reply.')
            ->assertDontSee('Send reply');

        $page->call('sendReply', 'Sneaky reply', $this->rid())
            ->assertReturned(['ok' => false, 'message' => 'You no longer have permission to reply.']);
        $this->assertSame(1, $this->storedThread($message)->message_count);
    }

    // ------------------------------------------------------------ unrelated administrators

    public function test_an_administrator_without_a_messaging_capability_sees_and_reaches_nothing(): void
    {
        $this->memberSends($this->messagingMember('Ann Jones'), 'Boots arrived damaged');
        $this->actingAs($this->unrelatedAdmin());

        $this->assertFalse(EmployeeMessages::canAccess());
        $this->assertFalse(EmployeeMessages::shouldRegisterNavigation());
        $this->assertNull(EmployeeMessages::getNavigationBadge());
        $this->assertFalse(EmployeeMessagesWidget::canView());

        Livewire::test(EmployeeMessages::class)->assertStatus(403);
        Livewire::test(EmployeeMessagesWidget::class)->assertStatus(403);
    }

    public function test_the_page_url_is_forbidden_for_an_unrelated_administrator(): void
    {
        $this->actingAs($this->unrelatedAdmin())->get('/admin/employee-messages')->assertForbidden();
    }

    public function test_the_dashboard_has_no_messages_card_for_an_unrelated_administrator(): void
    {
        $this->memberSends($this->messagingMember('Ann Jones'), 'Boots arrived damaged');
        foreach (['/admin', '/admin/logistics-board'] as $path) {
            $html = $this->actingAs($this->unrelatedAdmin())->get($path)->assertOk()->getContent();

            $this->assertStringNotContainsString('emsg-widget-title', $html);
            $this->assertStringNotContainsString('Boots arrived damaged', $html);
        }
    }

    public function test_a_role_revoked_between_requests_is_refused_on_the_next_poll(): void
    {
        $message = $this->memberSends($this->messagingMember());
        $responder = $this->logisticsResponder();
        $this->actingAs($responder);

        $page = Livewire::test(EmployeeMessages::class)->call('openThread', $message->thread_id);

        $responder->removeRole('logistics_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($responder->fresh());

        $page->call('poll')->assertStatus(403);
    }

    // ------------------------------------------------------------ Dashboard card

    public function test_the_dashboard_card_polls_without_marking_read_and_expanding_marks_read(): void
    {
        $message = $this->memberSends($this->messagingMember('Bo Smith'), 'Need a size swap');
        $this->actingAs($this->logisticsResponder());
        $this->assertTrue(EmployeeMessagesWidget::canView());

        $card = Livewire::test(EmployeeMessagesWidget::class)
            ->assertSee('Support Services Messages')
            ->assertSee('Bo Smith')
            ->assertSee('View all messages')
            ->assertDontSee('Reply to Bo Smith')
            ->call('poll');
        $this->assertNull($this->storedThread($message)->logistics_read_message_id);

        $card->call('toggleThread', $message->thread_id)
            ->assertSee('Need a size swap')
            ->assertSee('Reply to Bo Smith');
        $this->assertSame($message->id, $this->storedThread($message)->logistics_read_message_id);

        $card->call('toggleThread', $message->thread_id)->assertDontSee('Reply to Bo Smith');
    }

    public function test_a_reply_from_the_dashboard_card_works(): void
    {
        $message = $this->memberSends($this->messagingMember('Bo Smith'), 'Need a size swap');
        $this->actingAs($this->logisticsResponder());

        Livewire::test(EmployeeMessagesWidget::class)
            ->call('toggleThread', $message->thread_id)
            ->call('sendReply', 'Swap approved.', $this->rid())
            ->assertReturned(['ok' => true])
            ->assertSee('Swap approved.');

        $this->assertSame(2, $this->storedThread($message)->message_count);
    }

    public function test_the_dashboard_card_is_read_only_for_a_viewer(): void
    {
        $message = $this->memberSends($this->messagingMember('Bo Smith'), 'Need a size swap');
        $this->actingAs($this->logisticsViewer());

        $card = Livewire::test(EmployeeMessagesWidget::class)
            ->call('toggleThread', $message->thread_id)
            ->assertSee('You can read Support Services messages but not reply.')
            ->assertDontSee('Send reply');
        $card->call('sendReply', 'Nope', $this->rid())
            ->assertReturned(['ok' => false, 'message' => 'You no longer have permission to reply.']);
    }

    public function test_the_dashboard_card_has_an_empty_state(): void
    {
        $this->actingAs($this->logisticsResponder());

        Livewire::test(EmployeeMessagesWidget::class)
            ->assertSee('No Support Services messages yet.');
    }

    public function test_the_card_is_on_the_dashboard_but_not_in_display_mode(): void
    {
        $this->memberSends($this->messagingMember('Bo Smith'), 'Need a size swap please');
        $this->actingAs($this->logisticsResponder());

        // The messages card lives on the Logistics Board (the old /admin widget page).
        $normal = $this->get('/admin/logistics-board')->assertOk()->getContent();
        $this->assertStringContainsString('emsg-widget-title', $normal);
        $this->assertStringContainsString('View all messages', $normal);
        $this->assertStringContainsString('Need a size swap please', $normal);

        $display = $this->get('/admin?display=1')->assertOk()->getContent();
        $this->assertStringNotContainsString('emsg-widget-title', $display);
        $this->assertStringNotContainsString('View all messages', $display);
        $this->assertStringNotContainsString('Latest Support Services conversations', $display);
        $this->assertStringNotContainsString('Need a size swap please', $display);
    }

    public function test_the_dashboard_lists_the_messages_widget_after_the_station_board(): void
    {
        $this->actingAs($this->logisticsResponder());

        $widgets = (new \App\Filament\Pages\LogisticsBoard)->getWidgets();
        $this->assertSame([StationOperationsHubWidget::class, EmployeeMessagesWidget::class, \App\Filament\Widgets\EquipmentChangeRequestsWidget::class], $widgets);

        $this->app['request']->merge(['display' => '1']);
        $this->assertSame([StationOperationsHubWidget::class], (new Dashboard)->getWidgets());
    }

    // ------------------------------------------------------------ rendering and neighbours

    public function test_message_text_is_rendered_escaped_on_the_admin_page(): void
    {
        $payload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
        $message = $this->memberSends($this->messagingMember('Ann Jones'), $payload);
        $this->actingAs($this->logisticsResponder());

        $html = $this->get('/admin/employee-messages?thread='.$message->thread_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html);
    }

    public function test_a_responder_reaches_the_inbox_by_url_and_a_viewer_reaches_it_read_only(): void
    {
        $message = $this->memberSends($this->messagingMember('Cy Doe'));

        $this->actingAs($this->logisticsResponder())->get('/admin/employee-messages')->assertOk()->assertSee('Select a conversation');

        $this->actingAs($this->logisticsViewer())
            ->get('/admin/employee-messages?thread='.$message->thread_id)
            ->assertOk()
            ->assertSee('Cy Doe')
            ->assertSee('You can read Support Services messages but not reply.');
    }

    // Changed: the old Inbound and Outbound lists now lead into the Email Center, which loads for the same administrator.
    public function test_the_email_resources_still_load_for_a_super_administrator(): void
    {
        $this->actingAs($this->messagingAdmin(['super_admin'], ['admin.access']));

        $this->get('/admin/inbound-emails')->assertRedirect(\App\Filament\Admin\Pages\EmailCenter::getUrl());
        $this->get('/admin/outbound-emails')->assertRedirect(\App\Filament\Admin\Pages\EmailCenter::getUrl(['folder' => 'sent']));
        $this->get('/admin/email')->assertOk();
    }
}
