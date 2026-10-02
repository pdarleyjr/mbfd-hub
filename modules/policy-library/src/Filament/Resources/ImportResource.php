<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources;

use Filament\Tables;
use Filament\Tables\Table;
use Mbfd\PolicyLibrary\Models\ImportBatch;
use Mbfd\PolicyLibrary\Services\ImportSubmissionService;

final class ImportResource extends LibraryResource
{
    protected static ?string $model = ImportBatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Import Progress';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('source_filename')->label('Upload'), Tables\Columns\TextColumn::make('state')->badge(),
            Tables\Columns\TextColumn::make('status_message')->wrap()->label('Status'), Tables\Columns\TextColumn::make('created_at')->dateTime()->label('Submitted'),
        ])->poll('10s')->defaultSort('id', 'desc')->actions([
            Tables\Actions\Action::make('review')->label('Review draft')->url(fn (ImportBatch $record) => EditionResource::getUrl('edit', ['record' => $record->edition_ids[0] ?? 0], panel: 'policy-library'))->visible(fn (ImportBatch $record) => $record->state === 'ready'),
            Tables\Actions\Action::make('retry')->visible(fn (ImportBatch $record) => $record->state === 'failed')->action(function (ImportBatch $record): void {
                abort_unless(static::canViewAny(), 403);
                app(ImportSubmissionService::class)->retry($record);
            }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListImports::route('/')];
    }
}
