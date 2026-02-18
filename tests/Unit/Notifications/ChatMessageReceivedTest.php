<?php

namespace Tests\Unit\Notifications;

use App\Models\User;
use App\Models\ChMessage;
use App\Notifications\ChatMessageReceived;
use NotificationChannels\WebPush\WebPushMessage;
use Tests\TestCase;

class ChatMessageReceivedTest extends TestCase
{
    /**
     * Get WebPushMessage as array (properties are protected in v10+).
     */
    private function getMessageArray(WebPushMessage $message): array
    {
        return $message->toArray();
    }

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
        $array = $this->getMessageArray($webPushMessage);
        $this->assertStringContainsString('John Doe', $array['title']);
        $this->assertEquals('Test message content', $array['body']);
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
        
        $array = $this->getMessageArray($webPushMessage);
        $this->assertLessThan(150, strlen($array['body']));
        $this->assertStringContainsString('...', $array['body']);
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
        
        $array = $this->getMessageArray($webPushMessage);
        $this->assertEquals('/images/mbfd_app_icon_192.png', $array['icon']);
        $this->assertEquals('/images/mbfd_app_icon_96.png', $array['badge']);
    }

    public function test_notification_includes_action_data(): void
    {
        $sender = User::factory()->make(['name' => 'Test User']);
        $sender->id = 123;
        
        // ChMessage id is not fillable, so set it directly after construction
        $message = new ChMessage(['body' => 'Test']);
        $message->id = 456;
        
        $notification = new ChatMessageReceived($sender, $message);
        
        $webPushMessage = $notification->toWebPush(
            User::factory()->make(),
            $notification
        );
        
        $array = $this->getMessageArray($webPushMessage);
        $this->assertArrayHasKey('data', $array);
        $this->assertArrayHasKey('url', $array['data']);
        $this->assertArrayHasKey('message_id', $array['data']);
        $this->assertArrayHasKey('sender_id', $array['data']);
        $this->assertEquals('/admin/chat', $array['data']['url']);
        $this->assertEquals(456, $array['data']['message_id']);
        $this->assertEquals(123, $array['data']['sender_id']);
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
        
        // WebPushMessage::toArray() does not include 'options' (they are passed to the push library separately).
        // Verify the notification was constructed correctly by checking the WebPushMessage is an instance
        // and that the notification source code sets TTL via ->options(['TTL' => 3600]).
        // We verify this by inspecting the notification class directly.
        $this->assertInstanceOf(WebPushMessage::class, $webPushMessage);
        
        // Verify the notification source sets TTL by checking the ChatMessageReceived class
        // uses ->options(['TTL' => 3600]) - this is a structural test
        $reflection = new \ReflectionClass(ChatMessageReceived::class);
        $source = file_get_contents($reflection->getFileName());
        $this->assertStringContainsString("'TTL' => 3600", $source, 'Notification should set TTL to 3600');
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
