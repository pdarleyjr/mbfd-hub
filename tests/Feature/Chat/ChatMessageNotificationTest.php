<?php

namespace Tests\Feature\Chat;

use App\Models\User;
use App\Models\ChMessage;
use App\Notifications\ChatMessageReceived;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatMessageNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $sender;
    protected User $recipient;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->sender = User::factory()->create();
        $this->recipient = User::factory()->create();
    }

    public function test_sends_push_notification_when_chat_message_is_created(): void
    {
        Notification::fake();
        
        ChMessage::create([
            'from_id' => $this->sender->id,
            'to_id' => $this->recipient->id,
            'body' => 'Hello there!',
            'seen' => false,
        ]);
        
        Notification::assertSentTo(
            $this->recipient,
            ChatMessageReceived::class,
            function ($notification, $channels, $notifiable) {
                return $notification->message->body === 'Hello there!';
            }
        );
    }

    public function test_does_not_send_notification_for_seen_messages(): void
    {
        Notification::fake();
        
        ChMessage::create([
            'from_id' => $this->sender->id,
            'to_id' => $this->recipient->id,
            'body' => 'Already seen',
            'seen' => true,
        ]);
        
        Notification::assertNothingSent();
    }

    public function test_rate_limits_notifications_from_same_sender(): void
    {
        Notification::fake();
        
        // First message - should notify
        ChMessage::create([
            'from_id' => $this->sender->id,
            'to_id' => $this->recipient->id,
            'body' => 'Message 1',
            'seen' => false,
        ]);
        
        // Second message immediately - should be rate limited
        ChMessage::create([
            'from_id' => $this->sender->id,
            'to_id' => $this->recipient->id,
            'body' => 'Message 2',
            'seen' => false,
        ]);
        
        // Should only send one notification due to rate limiting
        Notification::assertSentToTimes($this->recipient, ChatMessageReceived::class, 1);
    }

    public function test_sends_separate_notifications_for_different_senders(): void
    {
        Notification::fake();
        
        $anotherSender = User::factory()->create();
        
        // First sender
        ChMessage::create([
            'from_id' => $this->sender->id,
            'to_id' => $this->recipient->id,
            'body' => 'Message from sender 1',
            'seen' => false,
        ]);
        
        // Different sender
        ChMessage::create([
            'from_id' => $anotherSender->id,
            'to_id' => $this->recipient->id,
            'body' => 'Message from sender 2',
            'seen' => false,
        ]);
        
        // Should send two notifications (different senders)
        Notification::assertSentToTimes($this->recipient, ChatMessageReceived::class, 2);
    }

    public function test_notification_includes_sender_information(): void
    {
        Notification::fake();
        
        ChMessage::create([
            'from_id' => $this->sender->id,
            'to_id' => $this->recipient->id,
            'body' => 'Test message',
            'seen' => false,
        ]);
        
        Notification::assertSentTo(
            $this->recipient,
            ChatMessageReceived::class,
            function ($notification) {
                return $notification->sender->id === $this->sender->id;
            }
        );
    }
}
