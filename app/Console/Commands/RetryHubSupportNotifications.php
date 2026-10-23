<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HubSupportNotificationDelivery;
use App\Services\HubSupport\HubSupportTicketSideEffectService;
use Illuminate\Console\Command;

final class RetryHubSupportNotifications extends Command
{
    protected $signature = 'support:retry-notifications {--limit=100}';

    protected $description = 'Retry a bounded batch of pending support notification deliveries';

    public function handle(HubSupportTicketSideEffectService $deliveries): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $pending = HubSupportNotificationDelivery::query()->whereNull('delivered_at')->whereNull('cancelled_at')
            ->where('attempts', '<', 5)->where(fn ($query) => $query->whereNull('last_attempted_at')->orWhere('last_attempted_at', '<=', now()->subMinutes(10)))
            ->orderBy('created_at')->limit($limit)->pluck('id');
        foreach ($pending as $id) {
            $deliveries->dispatch($id);
        }
        $this->info('Pending support notification handoffs attempted: '.$pending->count());

        return self::SUCCESS;
    }
}
