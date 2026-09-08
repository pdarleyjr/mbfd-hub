<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Communications\CloudflareUsageRefresher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class RefreshCloudflareUsage extends Command
{
    protected $signature = 'mbfd:cloudflare-usage-refresh';

    protected $description = 'Refresh authoritative Cloudflare email usage without sending email or modifying provider configuration';

    public function handle(CloudflareUsageRefresher $refresher): int
    {
        try {
            $budget = $refresher->refresh();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            Log::warning('Cloudflare email usage refresh failed; sending remains fail-closed.');

            return self::FAILURE;
        }
        $this->info('Cloudflare usage refreshed for '.CarbonImmutable::parse($budget->cycle_start)->format('Y-m-d').' through '.CarbonImmutable::parse($budget->cycle_end)->format('Y-m-d').'. No email was sent.');

        return self::SUCCESS;
    }
}
