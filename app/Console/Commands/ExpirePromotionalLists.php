<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Personnel\Promotions\PromotionalListService;
use Illuminate\Console\Command;

final class ExpirePromotionalLists extends Command
{
    protected $signature = 'personnel:expire-promotional-lists';

    protected $description = 'Mark promotional lists past their expiry date as expired and clear their current ranks.';

    public function handle(PromotionalListService $lists): int
    {
        $expired = $lists->expireDue();
        $this->components->info($expired === 0 ? 'No promotional lists reached their expiry date.' : $expired.' promotional list(s) expired.');

        return self::SUCCESS;
    }
}
