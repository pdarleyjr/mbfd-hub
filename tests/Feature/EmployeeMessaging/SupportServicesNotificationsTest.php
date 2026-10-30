<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeMessaging;

use App\Jobs\FanOutNotification;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Notifications\SupportServices\SupportServicesMessageNotification;
use App\Services\EmployeeMessaging\EmployeeMessageReason;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationDispatchService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use NotificationChannels\WebPush\WebPushChannel;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Support Services messaging alerts (docs/employee-logistics-messaging.md section 10), through the one
 * category registry, evaluator and dispatch service.
 */
final class SupportServicesNotificationsTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    private const SECRET_SUBJECT = 'Turnout coat zipper subject';

    private const SECRET_BODY = 'My coat is soaked with something and needs special handling';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        RateLimiter::clear('employee-logistics-message:1');
        Cache::flush();
        Queue::fake();
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** Run every queued fan-out the way the worker would, and return the jobs. @return list<FanOutNotification> */
    private function deliver(): array
    {
        $jobs = Queue::pushed(FanOutNotification::class)->values()->all();
        foreach ($jobs as $job) {
            $job->handle(app(NotificationDispatchService::class));
        }

        return $jobs;
    }

    /** @return list<int> user ids that were handed a SupportServicesMessageNotification */
    private function notifiedIds(): array
    {
        $ids = [];
        foreach ([User::query()->pluck('id')] as $all) {
            foreach ($all as $id) {
                if (Notification::sent(User::query()->find($id), SupportServicesMessageNotification::class)->isNotEmpty()) {
                    $ids[] = (int) $id;
                }
            }
        }

        return $ids;
    }

    private function superAdmin(): User
    {
        return $this->messagingAdmin(['super_admin'], ['admin.access']);
    }

    // ------------------------------------------------------------ category

    public function test_the_category_exists_in_the_registry_with_the_documented_shape(): void
    {
        $category = app(NotificationCategoryRegistry::class)->get('support_services_messages');

        $this->assertInstanceOf(NotificationCategory::class, $category);
        $this->assertSame(NotificationCategoryRegistry::GROUP_SUPPORT_SERVICES, $category->group);
        $this->assertSame(['database', 'webpush', 'email'], $category->channels);
        $this->assertTrue($category->isLocked('database'));
        $this->assertSame(['database' => true, 'webpush' => true, 'email' => true], $category->defaults);
        $this->assertTrue($category->senderWired);
    }

    // ------------------------------------------------------------ member -> Support Services

    public function test_a_member_message_alerts_the_support_audience_once_and_never_the_sender_or_outsiders(): void
    {
        $responder = $this->logisticsResponder();
        $viewer = $this->logisticsViewer();
        $super = $this->superAdmin();
        $unrelated = $this->unrelatedAdmin();
        $member = $this->messagingMember('Gia Gear');
        $bystander = $this->messagingMember('Bystander');

        $sent = $this->service()->startThread($member, self::SECRET_BODY, self::SECRET_SUBJECT, $this->rid(), EmployeeMessageReason::BUNKER_GEAR_CLEANING);

        $jobs = $this->deliver();
        $this->assertCount(1, $jobs);
        $this->assertSame('support_services_messages', $jobs[0]->categoryKey);
        $this->assertSame('support-msg:'.$sent->id, $jobs[0]->eventKey);
        $this->assertSame($member->id, $jobs[0]->exceptUserId);

        $notified = $this->notifiedIds();
        $this->assertContains($responder->id, $notified);
        $this->assertContains($viewer->id, $notified, 'view-only admins are told too: they can open the inbox');
        $this->assertContains($super->id, $notified);
        $this->assertNotContains($unrelated->id, $notified, 'Communications access alone is not Support Services access');
        $this->assertNotContains($member->id, $notified, 'the sender is excluded');
        $this->assertNotContains($bystander->id, $notified);
    }

    public function test_a_responder_who_is_also_the_sender_is_not_alerted_about_their_own_message(): void
    {
        $dual = $this->messagingMember('Dual Role');
        $dual->assignRole(\Spatie\Permission\Models\Role::findOrCreate('logistics_admin', 'web'));
        $dual->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('admin.access', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $other = $this->logisticsResponder();

        $this->service()->startThread($dual->fresh(), 'A question from a responder', null, $this->rid());
        $this->deliver();

        $this->assertContains($other->id, $this->notifiedIds());
        $this->assertNotContains($dual->id, $this->notifiedIds());
    }

    public function test_a_member_reply_in_an_existing_conversation_alerts_support_with_its_own_event_key(): void
    {
        $this->logisticsResponder();
        $member = $this->messagingMember();
        $first = $this->memberSends($member, 'First');
        $reply = $this->memberReplies($member, $first, 'Follow up');

        $keys = Queue::pushed(FanOutNotification::class)->map(fn (FanOutNotification $j) => $j->eventKey)->all();
        $this->assertSame(['support-msg:'.$first->id, 'support-msg:'.$reply->id], $keys);
    }

    // ------------------------------------------------------------ Support Services -> member

    public function test_a_support_services_reply_alerts_the_member_under_member_request_updates_not_the_replier(): void
    {
        $responder = $this->logisticsResponder();
        $member = $this->messagingMember('Gia Gear');
        $other = $this->messagingMember('Someone Else');
        $first = $this->memberSends($member, self::SECRET_BODY);
        Queue::fake();

        $reply = $this->logisticsReplies($responder, $first, 'Bring it Monday.');

        $jobs = $this->deliver();
        $this->assertCount(1, $jobs);
        $this->assertSame('member_request_updates', $jobs[0]->categoryKey);
        $this->assertSame('support-msg:'.$reply->id, $jobs[0]->eventKey);
        $this->assertSame($responder->id, $jobs[0]->exceptUserId);
        $this->assertSame([$member->id], $jobs[0]->candidateIds);
        $this->assertSame([$member->id], $this->notifiedIds());
        $this->assertNotContains($other->id, $this->notifiedIds());
    }

    // ------------------------------------------------------------ duplicates, retries, revocation

    public function test_a_retried_send_with_the_same_request_id_alerts_once(): void
    {
        $this->logisticsResponder();
        $member = $this->messagingMember();
        $requestId = $this->rid();

        $this->service()->startThread($member, 'Please clean it', null, $requestId);
        $this->service()->startThread($member, 'Please clean it', null, $requestId);

        $this->assertCount(1, Queue::pushed(FanOutNotification::class));
    }

    public function test_delivering_the_same_fan_out_twice_hands_each_person_one_notification(): void
    {
        $responder = $this->logisticsResponder();
        $this->memberSends($this->messagingMember(), 'Once only');

        $job = Queue::pushed(FanOutNotification::class)->first();
        $dispatcher = app(NotificationDispatchService::class);
        $job->handle($dispatcher);
        $job->handle($dispatcher);

        Notification::assertSentToTimes($responder, SupportServicesMessageNotification::class, 1);
    }

    public function test_a_responder_whose_role_is_revoked_before_delivery_gets_nothing(): void
    {
        $revoked = $this->logisticsResponder();
        $kept = $this->logisticsResponder();
        $this->memberSends($this->messagingMember(), 'Queued while still entitled');

        $revoked->removeRole('logistics_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->deliver();

        $notified = $this->notifiedIds();
        $this->assertContains($kept->id, $notified);
        $this->assertNotContains($revoked->id, $notified);

        // The notification itself also refuses at channel time.
        $notification = Queue::pushed(FanOutNotification::class)->first()->notification;
        $this->assertSame([], $notification->via($revoked->fresh()));
        $this->assertFalse($notification->shouldSend($revoked->fresh(), 'database'));
    }

    // ------------------------------------------------------------ privacy of push and email

    public function test_push_and_email_carry_no_name_subject_reason_or_message_text_but_in_app_does(): void
    {
        $responder = $this->logisticsResponder();
        $member = $this->messagingMember('Gia Gear');
        $this->service()->startThread($member, self::SECRET_BODY, self::SECRET_SUBJECT, $this->rid(), EmployeeMessageReason::BUNKER_GEAR_CLEANING);
        $notification = Queue::pushed(FanOutNotification::class)->first()->notification;

        $push = $notification->toWebPush($responder, null)->toArray();
        $email = $notification->toBudgetedEmail($responder);
        $lockScreenAndEmail = json_encode([$push, $email], JSON_THROW_ON_ERROR);
        foreach (['Gia', 'Gear', 'Turnout', 'zipper', 'soaked', 'Bunker', 'bunker'] as $leak) {
            $this->assertStringNotContainsString($leak, $lockScreenAndEmail, "'{$leak}' must not reach a lock screen or email preview");
        }
        $this->assertStringContainsString('New message - open MBFD Hub to read it.', $lockScreenAndEmail);

        $inApp = $notification->toDatabase($responder);
        $this->assertSame('New message from Gia Gear', $inApp['title']);
        $this->assertStringContainsString('Bunker gear cleaning request', $inApp['body']);
        $this->assertStringContainsString('soaked', $inApp['body']);
    }

    public function test_the_member_alert_is_also_content_free_on_the_lock_screen(): void
    {
        $responder = $this->logisticsResponder();
        $member = $this->messagingMember();
        $first = $this->memberSends($member, 'Hello');
        Queue::fake();
        $this->logisticsReplies($responder, $first, 'Your coat is ready, ask for Sergeant Private-Name.');
        $notification = Queue::pushed(FanOutNotification::class)->first()->notification;

        $json = json_encode([$notification->toWebPush($member, null)->toArray(), $notification->toBudgetedEmail($member)], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Private-Name', $json);
        $this->assertStringNotContainsString('coat', $json);
        $this->assertStringContainsString('Your coat is ready', $notification->toDatabase($member)['body'], 'full text stays in-app');
    }

    // ------------------------------------------------------------ preferences

    public function test_in_app_is_always_on_and_push_follows_the_saved_choice(): void
    {
        $responder = $this->logisticsResponder();
        $this->memberSends($this->messagingMember(), 'Hello');
        $notification = Queue::pushed(FanOutNotification::class)->first()->notification;

        $this->assertSame(['database'], $notification->via($responder), 'defaults are in-app only');

        $responder->updatePushSubscription('https://push.example.test/support-responder', str_repeat('a', 88), str_repeat('b', 24));
        UserNotificationSubscription::query()->create([
            'user_id' => $responder->id, 'event_key' => 'support_services_messages',
            'database_enabled' => false, 'webpush_enabled' => true, 'email_enabled' => false,
        ]);
        $via = $notification->via($responder->fresh());
        $this->assertContains('database', $via, 'the in-app channel is locked on');
        $this->assertContains(WebPushChannel::class, $via, 'opted-in push is honored');

        UserNotificationSubscription::query()->where('user_id', $responder->id)->update(['webpush_enabled' => false]);
        $this->assertNotContains(WebPushChannel::class, $notification->via($responder->fresh()), 'opt-out is honored');
    }

    // ------------------------------------------------------------ click-through

    public function test_click_through_urls_are_the_exact_conversation_and_never_send_an_unentitled_user_into_admin(): void
    {
        $responder = $this->logisticsResponder();
        $member = $this->messagingMember('Gia Gear');
        $sent = $this->memberSends($member, 'Hello');
        $supportAlert = Queue::pushed(FanOutNotification::class)->first()->notification;
        $threadId = $sent->thread_id;

        $this->assertSame(
            '/admin/employee-messages?thread='.$threadId,
            $supportAlert->toDatabase($responder)['actions'][0]['url'],
        );

        // Handed to someone who lost access (here: a plain member): lands on their own page, not /admin.
        $landing = $supportAlert->toDatabase($member)['actions'][0]['url'];
        $this->assertStringStartsNotWith('/admin', $landing);
        $this->assertSame('/employee', $landing);

        Queue::fake();
        $this->logisticsReplies($responder, $sent, 'Reply');
        $memberAlert = Queue::pushed(FanOutNotification::class)->first()->notification;
        $this->assertSame('/employee/messages?thread='.$threadId, $memberAlert->toDatabase($member)['actions'][0]['url']);
        $this->assertSame('/employee/messages?thread='.$threadId, $memberAlert->toWebPush($member, null)->toArray()['data']['url']);
    }

    public function test_the_alert_is_queued_on_a_queue_the_production_worker_runs(): void
    {
        $this->logisticsResponder();
        $this->memberSends($this->messagingMember(), 'Hello');
        $job = Queue::pushed(FanOutNotification::class)->first();

        $this->assertSame('notifications', $job->queue);
        $this->assertSame('notifications', $job->notification->queue);
        $this->assertSame(['database' => 'notifications', WebPushChannel::class => 'notifications', \App\Notifications\Channels\BudgetedMailChannel::class => 'notifications'], $job->notification->viaQueues());
    }
}
