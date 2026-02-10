<?php

namespace App\Observers;

use App\Models\ChMessage;
use App\Models\User;
use App\Notifications\ChatMessageReceived;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;

/**
 * ChMessage Observer
 * 
 * Observes ChMessage model events to send push notifications
 * when new chat messages are created.
 */
class ChMessageObserver
{
    /**
     * Handle the ChMessage "created" event.
     * 
     * Sends a push notification to the recipient when a new message is created.
     * Implements rate limiting to prevent notification spam (max 1 per 30 seconds
     * per sender-recipient pair).
     */
    public function created(ChMessage $message): void
    {
        // Only notify for unseen messages
        if ($message->seen) {
            return;
        }

        // Load the recipient and sender
        $recipient = User::find($message->to_id);
        $sender = User::find($message->from_id);

        // Validate both users exist
        if (!$recipient || !$sender) {
            Log::warning('ChatMessageObserver: User not found', [
                'message_id' => $message->id,
                'recipient_id' => $message->to_id,
                'sender_id' => $message->from_id,
            ]);
            return;
        }

        // Rate limit: max 1 notification per 30 seconds per sender-recipient pair
        // This prevents spam when users send multiple messages quickly
        $key = "chat-notification:{$sender->id}:{$recipient->id}";

        if (RateLimiter::tooManyAttempts($key, 1)) {
            Log::debug('ChatMessageObserver: Rate limit hit', [
                'sender_id' => $sender->id,
                'recipient_id' => $recipient->id,
            ]);
            return;
        }

        // Hit the rate limiter (30 second decay)
        RateLimiter::hit($key, 30);

        // Send the notification
        try {
            $recipient->notify(new ChatMessageReceived($sender, $message));
            
            Log::info('ChatMessageObserver: Notification sent', [
                'message_id' => $message->id,
                'sender_id' => $sender->id,
                'recipient_id' => $recipient->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Chat message notification failed', [
                'message_id' => $message->id,
                'sender_id' => $sender->id,
                'recipient_id' => $recipient->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
