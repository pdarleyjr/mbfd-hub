<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Communications\CloudflareDeliveryReconciler;
use Illuminate\Console\Command;
use RuntimeException;

final class ReconcileCloudflareEmailDelivery extends Command
{
    protected $signature = 'mbfd:email-delivery-reconcile {--backfill : Reconcile the retained 31-day provider window}';

    protected $description = 'Reconcile confirmed recipient delivery events without sending email';

    public function handle(CloudflareDeliveryReconciler $reconciler): int
    {
        try {
            $result = $reconciler->reconcile((bool) $this->option('backfill'));
            $this->info('Delivery events recorded: '.$result['events_recorded'].'; historical messages: '.$result['historical_messages'].'.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
