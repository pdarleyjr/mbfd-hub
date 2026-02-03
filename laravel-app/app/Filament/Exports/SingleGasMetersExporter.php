<?php

namespace App\Filament\Exports;

use App\Models\SingleGasMeter;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class SingleGasMetersExporter extends Exporter
{
    protected static ?string $model = SingleGasMeter::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('apparatus.designation')
                ->label('Apparatus Unit'),
            ExportColumn::make('apparatus.current_location')
                ->label('Station'),
            ExportColumn::make('serial_number')
                ->label('Serial Number'),
            ExportColumn::make('activation_date')
                ->label('Activation Date'),
            ExportColumn::make('expiration_date')
                ->label('Expiration Date'),
            ExportColumn::make('status')
                ->label('Status')
                ->state(fn (SingleGasMeter $record): string => $record->status),
            ExportColumn::make('created_at')
                ->label('Created At'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your single gas meters export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
