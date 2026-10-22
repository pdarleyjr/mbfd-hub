<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Personnel\PersonnelWorkbookReconciler;
use Illuminate\Console\Command;

final class ReconcilePersonnelWorkbook extends Command
{
    protected $signature = 'mbfd:personnel-workbook-report {path : Local XLSX workbook} {--actor= : Authorized administrator internal user ID} {--store : Save the encrypted review report}';

    protected $description = 'Compare the MBFD workbook without changing personnel, assignments or accounts';

    public function handle(PersonnelWorkbookReconciler $reconciler): int
    {
        $actor = User::query()->find($this->option('actor'));
        if ($actor === null || ! PersonnelWorkbookReconciler::canReconcile($actor)) {
            $this->error('An authorized administrator --actor ID is required.');

            return self::FAILURE;
        }
        $path = (string) $this->argument('path');
        if ($this->option('store')) {
            $report = $reconciler->store($actor, $path, basename($path));
            $this->line('Saved private review report '.$report->id);
            $summary = $report->summary;
        } else {
            $summary = $reconciler->analyze($actor, $path)['summary'];
        }
        $this->line(json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->info('Personnel and accounts unchanged.');

        return self::SUCCESS;
    }
}
