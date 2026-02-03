<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StationInventorySubmissionResource\Pages;
use App\Models\StationInventorySubmission;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StationInventorySubmissionResource extends Resource
{
    protected static ?string $model = StationInventorySubmission::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?string $navigationLabel = 'Inventory Submissions';
    protected static ?int $navigationSort = 2;

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Submission Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('station.name')
                            ->label('Station'),
                        Infolists\Components\TextEntry::make('employee_name')
                            ->label('Employee Name'),
                        Infolists\Components\TextEntry::make('shift')
                            ->label('Shift')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'A' => 'success',
                                'B' => 'warning',
                                'C' => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('submitted_at')
                            ->label('Submitted At')
                            ->dateTime('M j, Y g:i A T')
                            ->timezone('America/New_York'),
                        Infolists\Components\TextEntry::make('creator.name')
                            ->label('Created By'),
                    ])->columns(3),
                
                Infolists\Components\Section::make('Inventory Items')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('item_id')
                                    ->label('Item'),
                                Infolists\Components\TextEntry::make('quantity')
                                    ->label('Quantity'),
                            ])
                            ->columns(2),
                    ]),
                
                Infolists\Components\Section::make('Notes')
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->label('')
                            ->placeholder('No notes provided')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(fn ($record) => empty($record->notes)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('station.station_number')
                    ->label('Station')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('employee_name')
                    ->label('Employee')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('shift')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'A' => 'success',
                        'B' => 'warning',
                        'C' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->timezone('America/New_York'),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Created By')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('station_id')
                    ->label('Station')
                    ->relationship('station', 'station_number'),
                Tables\Filters\SelectFilter::make('shift')
                    ->options([
                        'A' => 'A Shift',
                        'B' => 'B Shift',
                        'C' => 'C Shift',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('download_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (StationInventorySubmission $record): string => 
                        route('download-inventory-pdf', ['submission' => $record->id])
                    )
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStationInventorySubmissions::route('/'),
            'view' => Pages\ViewStationInventorySubmission::route('/{record}'),
        ];
    }
}
