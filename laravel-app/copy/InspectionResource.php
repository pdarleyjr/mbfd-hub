<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InspectionResource\Pages;
use App\Models\ApparatusInspection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class InspectionResource extends Resource
{
    protected static ?string $model = ApparatusInspection::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Inspections';

    protected static ?string $modelLabel = 'Inspection';

    protected static ?int $navigationSort = 3;
    
    // Hide from navigation - inspections accessed via apparatus tabs
    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('apparatus_id')
                    ->relationship('apparatus', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                
                Forms\Components\DateTimePicker::make('inspection_date')
                    ->required()
                    ->default(now()),
                
                Forms\Components\TextInput::make('officer_name')
                    ->required()
                    ->maxLength(255),
                
                Forms\Components\TextInput::make('officer_badge')
                    ->maxLength(50),
                
                Forms\Components\Select::make('shift')
                    ->options([
                        'A' => 'A Shift',
                        'B' => 'B Shift',
                        'C' => 'C Shift',
                    ])
                    ->required(),
                
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull()
                    ->rows(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('inspection_date')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('apparatus.name')
                    ->label('Apparatus')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('officer_name')
                    ->label('Officer Name')
                    ->searchable(),
                
                Tables\Columns\BadgeColumn::make('shift')
                    ->colors([
                        'primary' => 'A',
                        'warning' => 'B',
                        'success' => 'C',
                    ]),
                
                Tables\Columns\TextColumn::make('defects_count')
                    ->label('Issues Count')
                    ->counts('defects')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
            ])
            ->filters([
                Filter::make('inspection_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('From Date'),
                        Forms\Components\DatePicker::make('until')
                            ->label('Until Date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('inspection_date', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('inspection_date', '<=', $date),
                            );
                    }),
                
                SelectFilter::make('apparatus_id')
                    ->label('Apparatus')
                    ->relationship('apparatus', 'name')
                    ->searchable()
                    ->preload(),
                
                SelectFilter::make('shift')
                    ->options([
                        'A' => 'A Shift',
                        'B' => 'B Shift',
                        'C' => 'C Shift',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('inspection_date', 'desc');
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
            'index' => Pages\ListInspections::route('/'),
            'view' => Pages\ViewInspection::route('/{record}'),
        ];
    }
}
