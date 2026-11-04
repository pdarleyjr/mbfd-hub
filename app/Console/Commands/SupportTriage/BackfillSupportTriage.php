<?php

declare(strict_types=1);

namespace App\Console\Commands\SupportTriage;

use App\Services\SupportTriage\EquipmentRuleService;
use App\Services\SupportTriage\TriageProjector;
use Illuminate\Console\Command;

/**
 * First-deploy projection: gives every open equipment issue, station request and supply request its Low/Medium/High
 * level from the equipment catalog. Staff overrides are kept and nothing is sent to anyone.
 */
final class BackfillSupportTriage extends Command
{
    protected $signature = 'support-triage:backfill {--dry-run : Show what would change without writing anything}';

    protected $description = 'Project Low/Medium/High priorities onto all currently open issues and requests (never sends anything)';

    public function handle(TriageProjector $projector, EquipmentRuleService $rules): int
    {
        if ($this->option('dry-run')) {
            $preview = $projector->previewReapply();
            $this->info("Open records: {$preview['open_records']}, would change: {$preview['changes']}, staff overrides kept: {$preview['overridden_kept']}");
            $this->line('Now:   '.json_encode($preview['by_level_before']));
            $this->line('After: '.json_encode($preview['by_level_after']));

            return self::SUCCESS;
        }

        $this->info('Catalog rows added: '.$rules->seedDefaults());
        $this->info('Priorities set or updated: '.$projector->reapply());

        return self::SUCCESS;
    }
}
