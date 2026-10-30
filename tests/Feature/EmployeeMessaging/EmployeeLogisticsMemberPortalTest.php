<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeMessaging;

use App\Filament\Employee\Pages\MyMessages;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The member side of Employee <-> Logistics messaging: home button, Employee Portal page,
 * dashboard shortcut (docs/employee-logistics-messaging.md section 1).
 */
final class EmployeeLogisticsMemberPortalTest extends TestCase
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

    public function test_home_shows_message_support_services_beside_vehicle_checkout_for_members_only(): void
    {
        $member = $this->messagingMember();
        $html = $this->actingAsCanonicalUser($member)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Vehicle Checkout', $html);
        $this->assertStringContainsString('data-home-message-logistics', $html);
        $this->assertStringContainsString('/employee/messages?compose=1', $html);
        $this->assertLessThan(
            strpos($html, 'data-home-message-logistics'),
            strpos($html, 'Vehicle Checkout'),
            'Message Support Services sits after Vehicle Checkout in the same actions group.'
        );
    }

    public function test_home_hides_message_support_services_for_an_account_without_a_personnel_profile(): void
    {
        $admin = $this->messagingAdmin(['super_admin']);
        $html = $this->actingAsCanonicalUser($admin)->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-home-message-logistics', $html);
    }

    public function test_the_compose_deep_link_opens_a_ready_composer(): void
    {
        $member = $this->messagingMember();
        $html = $this->actingAsCanonicalUser($member)->get('/employee/messages?compose=1')->assertOk()->getContent();

        $this->assertStringContainsString('New message to Support Services', $html);
        $this->assertStringContainsString('data-employee-message-composer', $html);
        $this->assertStringContainsString('mbfdEmployeeMessageComposer', $html);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/employee/messages?compose=1')->assertRedirect();
    }

    public function test_a_member_starts_a_conversation_and_reads_the_logistics_reply(): void
    {
        $member = $this->messagingMember('Jordan Rivera');
        $responder = $this->logisticsResponder();
        $this->actingAs($member);

        $page = Livewire::test(MyMessages::class, ['composing' => true]);
        $result = $page->call('startThread', 'My helmet strap broke.', 'Helmet', $this->rid())->effects['returns'][0] ?? null;
        $this->assertSame(['ok' => true], $result);

        $thread = EmployeeLogisticsThread::query()->sole();
        $this->assertSame($member->id, $thread->member_user_id);
        $this->assertSame('Helmet', $thread->subject);
        $page->assertSet('threadId', $thread->id)->assertSet('composing', false)->assertSee('My helmet strap broke.');

        $this->logisticsReplies($responder, $thread, 'Bring it to Logistics tomorrow.');
        $this->assertSame(1, $this->service()->memberUnreadCount($member));

        $this->actingAs($member);
        Livewire::test(MyMessages::class, ['threadId' => $thread->id])
            ->assertSee('Bring it to Logistics tomorrow.')
            ->assertSee('Support Services');
        $this->assertSame(0, $this->service()->memberUnreadCount($member), 'Opening the conversation reads it.');

        $reply = Livewire::test(MyMessages::class, ['threadId' => $thread->id])
            ->call('sendReply', 'Thanks, will do.', $this->rid())->effects['returns'][0] ?? null;
        $this->assertSame(['ok' => true], $reply);
        $this->assertSame(3, EmployeeLogisticsMessage::query()->where('thread_id', $thread->id)->count());
    }

    public function test_the_list_and_poll_never_mark_a_reply_read(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $this->logisticsReplies($this->logisticsResponder(), $first);

        $this->actingAs($member);
        Livewire::test(MyMessages::class)->assertSee('New')->call('poll')->call('showMore');

        $this->assertSame(1, $this->service()->memberUnreadCount($member));
    }

    public function test_a_poll_on_an_open_conversation_does_not_read_a_new_reply_but_reopening_does(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $responder = $this->logisticsResponder();

        $this->actingAs($member);
        $page = Livewire::test(MyMessages::class)->call('openThread', $first->thread_id);
        $this->actingAs($responder);
        $this->logisticsReplies($responder, $first, 'Reply that arrives during a poll');
        $this->actingAs($member);

        $page->call('poll')->assertSee('Reply that arrives during a poll');
        $this->assertSame(1, $this->service()->memberUnreadCount($member));

        $page->call('openThread', $first->thread_id);
        $this->assertSame(0, $this->service()->memberUnreadCount($member));
    }

    public function test_a_reply_meant_for_another_conversation_is_refused(): void
    {
        $member = $this->messagingMember();
        $a = $this->memberSends($member, 'First topic');
        $b = $this->memberSends($member, 'Second topic');
        $this->actingAs($member);

        $result = Livewire::test(MyMessages::class)->call('openThread', $b->thread_id)
            ->call('sendReply', 'Meant for the first topic', $this->rid(), $a->thread_id)->effects['returns'][0] ?? null;

        $this->assertFalse($result['ok']);
        $this->assertSame(2, EmployeeLogisticsMessage::query()->count());
    }

    public function test_a_member_cannot_open_or_reply_to_another_members_conversation(): void
    {
        $owner = $this->messagingMember('Owner One');
        $other = $this->messagingMember('Other Two');
        $message = $this->memberSends($owner, 'Private request about my locker.');

        $this->actingAs($other);
        $page = Livewire::test(MyMessages::class, ['threadId' => $message->thread_id])
            ->assertDontSee('Private request about my locker.')
            ->assertSee('Conversation not found');
        $result = $page->call('sendReply', 'Sneaky', $this->rid())->effects['returns'][0] ?? null;

        $this->assertFalse($result['ok']);
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());

        $result = Livewire::test(MyMessages::class)->set('threadId', $message->thread_id)
            ->call('sendReply', 'Tampered property', $this->rid())->effects['returns'][0] ?? null;
        $this->assertFalse($result['ok']);
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
    }

    public function test_a_malformed_thread_link_falls_back_to_the_list(): void
    {
        $member = $this->messagingMember();
        $this->actingAsCanonicalUser($member)->get('/employee/messages?thread=abc')->assertOk()->assertSee('Conversations with Support Services');
        $this->get('/employee/messages?thread=999999')->assertOk()->assertSee('Conversation not found');
    }

    public function test_validation_errors_come_back_as_a_message_and_nothing_is_saved(): void
    {
        $member = $this->messagingMember();
        $this->actingAs($member);

        $result = Livewire::test(MyMessages::class, ['composing' => true])
            ->call('startThread', '   ', '', $this->rid())->effects['returns'][0] ?? null;

        $this->assertFalse($result['ok']);
        $this->assertSame('Type a message before sending.', $result['message']);
        $this->assertSame(0, EmployeeLogisticsThread::query()->count());
    }

    public function test_a_retried_send_does_not_create_a_duplicate(): void
    {
        $member = $this->messagingMember();
        $this->actingAs($member);
        $id = $this->rid();

        Livewire::test(MyMessages::class, ['composing' => true])->call('startThread', 'Need gloves, size L.', '', $id);
        Livewire::test(MyMessages::class, ['composing' => true])->call('startThread', 'Need gloves, size L.', '', $id);

        $this->assertSame(1, EmployeeLogisticsThread::query()->count());
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
    }

    public function test_message_text_is_escaped_when_displayed(): void
    {
        $member = $this->messagingMember();
        $message = $this->memberSends($member, '<script>alert("x")</script><b>bold</b>');

        $html = $this->actingAsCanonicalUser($member)->get('/employee/messages?thread='.$message->thread_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert("x")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
    }

    public function test_accounts_without_member_access_cannot_open_the_page(): void
    {
        $member = $this->messagingMember();
        $member->forceFill(['must_change_password' => true])->save();
        $this->actingAs($member->fresh());

        $this->assertFalse(MyMessages::canAccess());
    }

    public function test_the_employee_dashboard_links_to_my_messages_with_the_unread_count(): void
    {
        $member = $this->messagingMember();
        $this->logisticsReplies($this->logisticsResponder(), $this->memberSends($member));

        $html = $this->actingAsCanonicalUser($member)->get('/employee/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('data-employee-messages-shortcut', $html);
        $this->assertStringContainsString('Message Support Services', $html);
        $this->assertStringContainsString('1 new reply in My Messages', $html);
        $this->assertStringContainsString('My Equipment', $html);
        $this->assertStringContainsString('Request Uniforms', $html);
    }
}
