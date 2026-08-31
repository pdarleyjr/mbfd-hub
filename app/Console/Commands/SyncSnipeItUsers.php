<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class SyncSnipeItUsers extends Command
{
    protected $signature = 'snipeit:sync-users';

    protected $description = 'Blocked legacy Snipe-IT writer; use the no-write reconciliation preview instead.';

    public function handle(): int
    {
        $this->error('snipeit:sync-users is blocked because it could create or alter Snipe-IT identities. Run snipeit:reconcile-identities --preview instead.');

        return self::FAILURE;
    }
}
