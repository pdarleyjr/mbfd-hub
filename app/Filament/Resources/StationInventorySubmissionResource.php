<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\StationInventorySubmissionResource\Pages;
use App\Models\StationInventorySubmission;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class StationInventorySubmissionResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = StationInventorySubmission::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Station Management';

    protected static ?string $navigationLabel = 'Inventory Submissions';

    protected static ?string $modelLabel = 'inventory submission';

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('Submission')->formatStateUsing(fn ($state): string => '#'.$state)->sortable(),
                Tables\Columns\TextColumn::make('station.station_number')->label('Station')->formatStateUsing(fn ($state): string => 'Station '.$state)->sortable(),
                Tables\Columns\TextColumn::make('employee_name')->label('Submitted By')->searchable(),
                Tables\Columns\TextColumn::make('shift')->badge(),
                Tables\Columns\TextColumn::make('items_count')->label('Items')->state(fn (StationInventorySubmission $record): int => count($record->items ?? [])),
                Tables\Columns\TextColumn::make('submitted_at')->label('Submitted')->dateTime('M j, Y g:i A')->timezone('America/New_York')->sortable(),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->bulkActions([])
            ->defaultSort('submitted_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Submitted inventory evidence')
                ->description('This signed submission snapshot is immutable. Supply follow-up is managed through the station supply request workflow.')
                ->schema([
                    Infolists\Components\TextEntry::make('id')->label('Submission'),
                    Infolists\Components\TextEntry::make('station.station_number')->label('Station')->formatStateUsing(fn ($state): string => 'Station '.$state),
                    Infolists\Components\TextEntry::make('employee_name')->label('Submitted By'),
                    Infolists\Components\TextEntry::make('shift')->label('Shift')->badge(),
                    Infolists\Components\TextEntry::make('submitted_at')->label('Submitted')->dateTime('M j, Y g:i A')->timezone('America/New_York'),
                    Infolists\Components\TextEntry::make('notes')->label('Submission Notes')->placeholder('—')->columnSpanFull(),
                ])->columns(2),
            Infolists\Components\Section::make('Submitted item snapshot')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Infolists\Components\TextEntry::make('category_id')->label('Category'),
                            Infolists\Components\TextEntry::make('item_id')->label('Item'),
                            Infolists\Components\TextEntry::make('quantity')->label('Quantity'),
                        ])->columns(3),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStationInventorySubmissions::route('/'),
            'view' => Pages\ViewStationInventorySubmission::route('/{record}'),
        ];
    }
}
