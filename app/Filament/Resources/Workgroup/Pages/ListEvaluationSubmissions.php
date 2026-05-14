<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Exports\EvaluationSubmissionExporter;
use App\Filament\Resources\Workgroup\EvaluationSubmissionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEvaluationSubmissions extends ListRecords
{
    protected static string $resource = EvaluationSubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ExportAction::make()
                ->exporter(EvaluationSubmissionExporter::class)
                ->label('Export All Submissions')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => (auth()->user()?->hasAnyRole(['super_admin', 'admin', 'logistics_admin']) ?? false)),
        ];
    }
}
