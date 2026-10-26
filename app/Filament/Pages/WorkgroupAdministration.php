<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\HasDatasetExportActions;
use App\Models\User;
use App\Services\Exports\ExportDataset;

final class WorkgroupAdministration extends \App\Filament\Workgroup\Pages\AdminDashboard
{
    use HasDatasetExportActions;

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationGroup = 'Programs';

    protected static ?string $navigationLabel = 'Workgroups';

    protected static ?string $title = 'Workgroup Administration';

    protected static ?string $slug = 'workgroup-administration';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('admin.workgroups.view') ?? false;
    }

    public function canExportData(User $actor): bool
    {
        return $actor->can('admin.workgroups.view');
    }

    protected function getHeaderActions(): array
    {
        return [$this->datasetExportAction(), ...parent::getHeaderActions()];
    }

    protected function exportDataset(User $actor): ExportDataset
    {
        abort_unless($this->canExportData($actor), 403);
        $data = parent::getViewData();
        $rows = [];
        foreach ($data['stats'] as $stat) {
            $rows[] = ['Overview', $stat['label'], $stat['value'], $stat['desc']];
        }
        $range = ['Data range: all retained workgroup data'];
        if ($data['progress'] !== null) {
            foreach (['total_products' => 'Products', 'total_members' => 'Evaluators', 'submitted_submissions' => 'Submitted', 'completion_percentage' => 'Completion (%)'] as $key => $label) {
                $rows[] = ['Active session', $label, $data['progress'][$key], $data['activeSession']->name];
            }
            $range[] = 'Active session: '.$data['activeSession']->name;
            $range[] = 'Session calendar dates: '.($data['activeSession']->start_date?->format('Y-m-d') ?? 'Not set')
                .' to '.($data['activeSession']->end_date?->format('Y-m-d') ?? 'Not set');
        }

        return ExportDataset::fromRows($actor, 'Workgroup Administration', ['Section', 'Metric', 'Value', 'Details'], $rows, 'Current analytics view', $range);
    }
}
