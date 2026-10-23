<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\HubSupport\HubSupportTicketSideEffectService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverHubSupportTicketUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public readonly string $deliveryId)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 60, 300, 600];
    }

    public function handle(HubSupportTicketSideEffectService $deliveries): void
    {
        $deliveries->deliver($this->deliveryId);
    }
}
