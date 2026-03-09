<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Filament\Resources\ApparatusResource;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;

class ViewInspection extends Page
{
    protected static string $resource = ApparatusResource::class;

    protected static string $view = 'filament.resources.apparatus-resource.pages.view-inspection';

    public Apparatus $record;
    public ApparatusInspection $inspection;

    public function mount(int|string $record, int|string $inspection): void
    {
        $this->record = Apparatus::findOrFail($record);
        $this->inspection = ApparatusInspection::with('defects')->findOrFail($inspection);
    }

    public function getTitle(): string
    {
        $designation = $this->record->designation ?? $this->inspection->designation_at_time ?? 'Unknown';
        return "Inspection Results — {$designation}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print Results')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->extraAttributes([
                    'onclick' => 'window.print(); return false;',
                ]),
            Action::make('back')
                ->label('Back to Apparatus')
                ->icon('heroicon-o-arrow-left')
                ->url(ApparatusResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    protected function getViewData(): array
    {
        $results = $this->inspection->results ?? [];
        $defects = $this->inspection->defects;
        $currentDesignation = $this->record->designation ?? $this->inspection->designation_at_time ?? '—';

        // Compute stats
        $totalItems = 0;
        $presentCount = 0;
        $missingCount = 0;
        $damagedCount = 0;

        foreach ($results as $compartment) {
            foreach ($compartment['items'] ?? [] as $item) {
                $totalItems++;
                $status = $item['status'] ?? 'Present';
                if ($status === 'Present') $presentCount++;
                elseif ($status === 'Missing') $missingCount++;
                elseif ($status === 'Damaged') $damagedCount++;
            }
        }

        return [
            'inspection' => $this->inspection,
            'apparatus' => $this->record,
            'currentDesignation' => $currentDesignation,
            'results' => $results,
            'defects' => $defects,
            'totalItems' => $totalItems,
            'presentCount' => $presentCount,
            'missingCount' => $missingCount,
            'damagedCount' => $damagedCount,
        ];
    }
}
