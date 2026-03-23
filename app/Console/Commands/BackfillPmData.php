<?php

namespace App\Console\Commands;

use Database\Seeders\PmCycleDataSeeder;
use Illuminate\Console\Command;

class BackfillPmData extends Command
{
    protected $signature = 'apparatus:backfill-pm-data';

    protected $description = 'Backfill PM cycle data for all apparatuses from CSV baseline';

    public function handle(): int
    {
        $this->info('Starting PM data backfill...');

        $seeder = new PmCycleDataSeeder();
        $seeder->setCommand($this);
        $seeder->run();

        return self::SUCCESS;
    }
}
