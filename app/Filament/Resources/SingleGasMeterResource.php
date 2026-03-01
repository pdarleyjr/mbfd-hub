<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SingleGasMeterResource\Pages;
use App\Models\Apparatus;
use App\Models\SingleGasMeter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SingleGasMeterResource extends Resource
{
    protected static ?string $model = SingleGasMeter::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';
    
    protected static ?string $navigationGroup = 'Inventory & Logistics';
    
    protected static ?string $navigationLabel = 'Single Gas Meters';
    
    protected static ?string $pluralModelLabel = 'Single Gas Meters';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Gas Meter Information')
                    ->schema([
                        Forms\Components\Select::make('apparatus_id')
                            ->label('Apparatus')
                            ->relationship(
                                name: 'apparatus',
                                titleAttribute: 'designation',
                                modifyQueryUsing: fn (Builder $query) => $query->whereNotNull('designation')
                            )
                            ->getOptionLabelFromRecordUsing(fn (Apparatus $record) => $record->designation ?? 'Unknown')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('Select the apparatus this meter is assigned to'),
                        
                        Forms\Components\TextInput::make('serial_number')
                            ->label('Serial Number')
                            ->required()
                            ->maxLength(5)
                            ->minLength(5)
                            ->alphaNum()
                            ->unique(ignoreRecord: true)
                            ->helperText('Enter the last 5 digits of the serial number')
                            ->placeholder('12345'),
                        
                        Forms\Components\DatePicker::make('activation_date')
                            ->label('Activation Date')
                            ->required()
                            ->maxDate(now())
                            ->helperText('Date must not be in the future')
                            ->reactive()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state) {
                                    $set('expiration_date', \Carbon\Carbon::parse($state)->addYears(2)->format('Y-m-d'));
                                }
                            }),
                        
                        Forms\Components\DatePicker::make('expiration_date')
                            ->label('Expiration Date')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Auto-calculated as activation date + 2 years'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('apparatus.designation')
                    ->label('Apparatus')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('serial_number')
                    ->label('Serial Number')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('activation_date')
                    ->label('Activation Date')
                    ->date()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('expiration_date')
                    ->label('Expiration Date')
                    ->date()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Valid' => 'success',
                        'Expired' => 'danger',
                        default => 'gray',
                    }),
                
                Tables\Columns\TextColumn::make('daysUntilExpiration')
                    ->label('Days Until Expiration')
                    ->state(fn (SingleGasMeter $record): int => $record->daysUntilExpiration())
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('expiration_date', $direction);
                    })
                    ->suffix(' days'),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('expiration_date', 'asc')
            ->striped()
            ->filters([
                Tables\Filters\SelectFilter::make('apparatus')
                    ->relationship(
                        name: 'apparatus',
                        titleAttribute: 'designation',
                        modifyQueryUsing: fn (Builder $query) => $query->whereNotNull('designation')
                    )
                    ->searchable()
                    ->preload()
                    ->label('Filter by Apparatus'),
                
                Tables\Filters\Filter::make('expired')
                    ->query(fn (Builder $query): Builder => $query->where('expiration_date', '<', now()))
                    ->label('Expired Only'),
                
                Tables\Filters\Filter::make('expiring_soon')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('expiration_date', '>', now())
                        ->where('expiration_date', '<=', now()->addDays(90)))
                    ->label('Expiring in 90 Days'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ExportBulkAction::make()
                        ->exporter(\App\Filament\Exports\SingleGasMetersExporter::class),
                ]),
            ])
            ->headerActions([
                Tables\Actions\ExportAction::make()
                    ->exporter(\App\Filament\Exports\SingleGasMetersExporter::class),
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
            'index' => Pages\ListSingleGasMeters::route('/'),
            'create' => Pages\CreateSingleGasMeter::route('/create'),
            'edit' => Pages\EditSingleGasMeter::route('/{record}/edit'),
        ];
    }
}
