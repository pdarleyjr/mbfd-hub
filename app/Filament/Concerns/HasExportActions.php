<?php

namespace App\Filament\Concerns;

use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

trait HasExportActions
{
    /**
     * Returns a configured ExportAction for use in table headerActions.
     */
    protected static function makeExportHeaderAction(string $filenamePrefix = 'mbfd_export'): ExportAction
    {
        return ExportAction::make('export')
            ->label('Export')
            ->color('gray')
            ->icon('heroicon-o-arrow-down-tray')
            ->exports([
                ExcelExport::make('xlsx')
                    ->label('Export as Excel (.xlsx)')
                    ->fromTable()
                    ->withFilename($filenamePrefix . '_' . date('Y-m-d')),
                ExcelExport::make('csv')
                    ->label('Export as CSV (.csv)')
                    ->fromTable()
                    ->withFilename($filenamePrefix . '_' . date('Y-m-d'))
                    ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
            ]);
    }

    /**
     * Returns a configured ExportBulkAction for use in table bulkActions.
     */
    protected static function makeExportBulkAction(string $filenamePrefix = 'mbfd_export'): ExportBulkAction
    {
        return ExportBulkAction::make('export_selected')
            ->label('Export Selected')
            ->exports([
                ExcelExport::make('xlsx')
                    ->label('Export as Excel (.xlsx)')
                    ->fromTable()
                    ->withFilename($filenamePrefix . '_selected_' . date('Y-m-d')),
                ExcelExport::make('csv')
                    ->label('Export as CSV (.csv)')
                    ->fromTable()
                    ->withFilename($filenamePrefix . '_selected_' . date('Y-m-d'))
                    ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
            ]);
    }
}
