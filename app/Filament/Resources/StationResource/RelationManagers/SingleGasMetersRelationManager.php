<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Models\Apparatus;
use App\Models\SingleGasMeter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SingleGasMetersRelationManager extends RelationManager
{
    protected static string $relationship = 'singleGasMeters';

    protected static ?string $title = 'Single Gas Meters';

    protected static ?string $icon = 'heroicon-o-beaker';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('apparatus_id')
                    ->label('Apparatus')
                    ->options(function (RelationManager $livewire) {
                        // Get only apparatuses at this station
                        $station = $livewire->getOwnerRecord();
                        return Apparatus::where('current_location', $station->station_number)
                            ->pluck('designation', 'id');
                    })
                    ->required()
                    ->searchable()
                    ->helperText('Select the apparatus this meter is assigned to'),
                
                Forms\Components\TextInput::make('serial_number')
                    ->label('Serial Number')
                    ->required()
                    ->maxLength(5)
                    ->minLength(5)
                    ->alphaNum()
                    ->unique(SingleGasMeter::class, 'serial_number', ignoreRecord: true)
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
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('serial_number')
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
                    ->state(function (SingleGasMeter $record): int {
                        return $record->daysUntilExpiration();
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('expiration_date', $direction);
                    })
                    ->suffix(' days'),
            ])
            ->defaultSort('expiration_date', 'asc')
            ->filters([
                Tables\Filters\Filter::make('expired')
                    ->query(fn ($query) => $query->where('expiration_date', '<', now()))
                    ->label('Expired Only'),
                
                Tables\Filters\Filter::make('expiring_soon')
                    ->query(fn ($query) => $query
                        ->where('expiration_date', '>', now())
                        ->where('expiration_date', '<=', now()->addDays(90)))
                    ->label('Expiring in 90 Days'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Single Gas Meter'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
