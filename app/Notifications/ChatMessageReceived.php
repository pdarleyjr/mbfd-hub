<?php

namespace App\Notifications;

use App\Models\ChMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Chat Message Received Notification
 * 
 * Sends a web push notification when a user receives a new chat message.
 */
class ChatMessageReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected User $sender,
        protected ChMessage $message
    ) {}

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return [WebPushChannel::class];
    }

    /**
     * Get the web push representation of the notification.
     */
    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage())
            ->title("New message from {$this->sender->name}")
            ->body(Str::limit($this->message->body, 100))
            ->icon('/images/mbfd_app_icon_192.png')
            ->badge('/images/mbfd_app_icon_96.png')
            ->action('Open Chat', 'open-chat')
            ->data([
                'url' => '/admin/chat',
                'message_id' => $this->message->id,
                'sender_id' => $this->sender->id,
            ])
            ->options(['TTL' => 3600]) // 1 hour time-to-live
            ->vibrate([200, 100, 200]);
    }

    /**
     * Determine which queues should be used for the notification.
     */
    public function viaQueues(): array
    {
        return [
            WebPushChannel::class => 'notifications',
        ];
    }
}
