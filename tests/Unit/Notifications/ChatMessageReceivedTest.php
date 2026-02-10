<?php

namespace Tests\Unit\Notifications;

use App\Models\User;
use App\Models\ChMessage;
use App\Notifications\ChatMessageReceived;
use NotificationChannels\WebPush\WebPushMessage;
use Tests\TestCase;

class ChatMessageReceivedTest extends TestCase
{
    public function test_creates_correct_web_push_message(): void
    {
        $sender = User::factory()->make(['name' => 'John Doe']);
        $message = new ChMessage(['body' => 'Test message content']);
        
        $notification = new ChatMessageReceived($sender, $message);
        
        $webPushMessage = $notification->toWebPush(
            User::factory()->make(),
            $notification
        );
        
        $this->assertInstanceOf(WebPushMessage::class, $webPushMessage);
        $this->assertStringContainsString('John Doe', $webPushMessage->title);
        $this->assertEquals('Test message content', $webPushMessage->body);
    }

    public function test_truncates_long_messages_in_notification(): void
    {
        $sender = User::factory()->make(['name' => 'Jane Doe']);
        $longMessage = new ChMessage(['body' => str_repeat('a', 200)]);
        
        $notification = new ChatMessageReceived($sender, $longMessage);
        
        $webPushMessage = $notification->toWebPush(
            User::factory()->make(),
            $notification
        );
        
        $this->assertLessThan(150, strlen($webPushMessage->body));
        $this->assertStringContainsString('...', $webPushMessage->body);
    }

    public function test_notification_includes_correct_icon(): void
    {
        $sender = User::factory()->make(['name' => 'Test User']);
        $message = new ChMessage(['body' => 'Test']);
        
        $notification = new ChatMessageReceived($sender, $message);
        
        $webPushMessage = $notification->toWebPush(
            User::factory()->make(),
            $notification
        );
        
        $this->assertEquals('/images/mbfd_app_icon_192.png', $webPushMessage->icon);
        $this->assertEquals('/images/mbfd_app_icon_96.png', $webPushMessage->badge);
    }

    public function test_notification_includes_action_data(): void
    {
        $sender = User::factory()->make(['name' => 'Test User', 'id' => 123]);
        $message = new ChMessage(['body' => 'Test', 'id' => 456]);
        
        $notification = new ChatMessageReceived($sender, $message);
        
        $webPushMessage = $notification->toWebPush(
            User::factory()->make(),
            $notification
        );
        
        $this->assertArrayHasKey('url', $webPushMessage->data);
        $this->assertArrayHasKey('message_id', $webPushMessage->data);
        $this->assertArrayHasKey('sender_id', $webPushMessage->data);
        $this->assertEquals('/admin/chat', $webPushMessage->data['url']);
        $this->assertEquals(456, $webPushMessage->data['message_id']);
        $this->assertEquals(123, $webPushMessage->data['sender_id']);
    }

    public function test_notification_uses_web_push_channel(): void
    {
        $sender = User::factory()->make();
        $message = new ChMessage(['body' => 'Test']);
        
        $notification = new ChatMessageReceived($sender, $message);
        
        $channels = $notification->via(User::factory()->make());
        
        $this->assertContains(\NotificationChannels\WebPush\WebPushChannel::class, $channels);
    }

    public function test_notification_has_ttl_options(): void
    {
        $sender = User::factory()->make(['name' => 'Test User']);
        $message = new ChMessage(['body' => 'Test']);
        
        $notification = new ChatMessageReceived($sender, $message);
        
        $webPushMessage = $notification->toWebPush(
            User::factory()->make(),
            $notification
        );
        
        $this->assertArrayHasKey('TTL', $webPushMessage->options);
        $this->assertEquals(3600, $webPushMessage->options['TTL']);
    }

    public function test_notification_queues_to_notifications_queue(): void
    {
        $sender = User::factory()->make();
        $message = new ChMessage(['body' => 'Test']);
        
        $notification = new ChatMessageReceived($sender, $message);
        
        $queues = $notification->viaQueues();
        
        $this->assertArrayHasKey(\NotificationChannels\WebPush\WebPushChannel::class, $queues);
        $this->assertEquals('notifications', $queues[\NotificationChannels\WebPush\WebPushChannel::class]);
    }
}
