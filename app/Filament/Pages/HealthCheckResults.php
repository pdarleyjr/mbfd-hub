<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\HasDatasetExportActions;
use App\Models\User;
use App\Services\Exports\ExportDataset;
use ShuvroRoy\FilamentSpatieLaravelHealth\Pages\HealthCheckResults as BaseHealthCheckResults;
use Spatie\Health\ResultStores\ResultStore;

class HealthCheckResults extends BaseHealthCheckResults
{
    use HasDatasetExportActions;

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 40;

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('admin.system.view') ?? false;
    }

    public function canExportData(User $actor): bool
    {
        return $actor->can('admin.system.view');
    }

    protected function getHeaderActions(): array
    {
        return [...parent::getHeaderActions(), $this->datasetExportAction()];
    }

    protected function exportDataset(User $actor): ExportDataset
    {
        abort_unless($this->canExportData($actor), 403);
        $results = app(ResultStore::class)->latestResults();
        $rows = [];
        foreach ($results->storedCheckResults ?? [] as $result) {
            $rows[] = [$result->label, $result->status, $result->shortSummary, $result->notificationMessage, $results->finishedAt];
        }

        return ExportDataset::fromRows($actor, 'System Health', ['Check', 'Status', 'Summary', 'Message', 'Checked at'], $rows,
            'Latest stored health results', ['Snapshot: '.($results === null ? 'No stored results' : 'Last completed checks; exporting does not run checks')]);
    }
}
