<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Notifications\Channels\BudgetedMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-sends one alert email that the email budget's per-minute limit refused (see BudgetedMailChannel).
 * The recipient is re-evaluated first, so a role revoked or an email preference switched off while the
 * email waited stops it.
 */
final class DeliverDeferredEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly Notification $notification,
        public readonly Model $notifiable,
        public readonly int $attempt,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(BudgetedMailChannel $channel): void
    {
        if (method_exists($this->notification, 'shouldSend') && ! $this->notification->shouldSend($this->notifiable, BudgetedMailChannel::class)) {
            return;
        }

        $channel->sendAttempt($this->notifiable, $this->notification, $this->attempt);
    }
}
