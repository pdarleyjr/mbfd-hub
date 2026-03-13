<?php

namespace App\Console\Commands;

use App\Models\Apparatus;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BackfillApparatusSlugs extends Command
{
    protected $signature = 'apparatus:backfill-slugs';
    protected $description = 'Backfill slug field for all apparatuses that have a designation but no slug';

    public function handle(): int
    {
        $apparatuses = Apparatus::whereNull('slug')
            ->orWhere('slug', '')
            ->get();

        $updated = 0;

        foreach ($apparatuses as $apparatus) {
            if (!empty($apparatus->designation)) {
                $apparatus->slug = Str::slug($apparatus->designation);
                $apparatus->saveQuietly();
                $updated++;
                $this->line("  ✓ {$apparatus->designation} → {$apparatus->slug}");
            } else {
                $this->warn("  ⚠ Apparatus #{$apparatus->id} has no designation — skipped");
            }
        }

        $this->info("Backfilled {$updated} apparatus slugs.");

        return self::SUCCESS;
    }
}
