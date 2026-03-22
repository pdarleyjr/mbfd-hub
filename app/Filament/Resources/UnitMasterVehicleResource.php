<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UnitMasterVehicleResource\Pages;
use App\Models\UnitMasterVehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UnitMasterVehicleResource extends Resource
{
    protected static ?string $model = UnitMasterVehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Fleet Management';

    protected static ?string $navigationLabel = 'Unit Master Inventory';

    protected static ?string $modelLabel = 'Unit Master Vehicle';

    protected static ?string $pluralModelLabel = 'Unit Master Inventory';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Vehicle Information')
                    ->schema([
                        Forms\Components\TextInput::make('veh_number')
                            ->label('Veh #')
                            ->nullable(),
                        Forms\Components\TextInput::make('tag_number')
                            ->label('Tag #')
                            ->nullable(),
                        Forms\Components\TextInput::make('make')
                            ->nullable(),
                        Forms\Components\TextInput::make('model')
                            ->nullable(),
                        Forms\Components\TextInput::make('year')
                            ->nullable(),
                        Forms\Components\TextInput::make('serial_number')
                            ->label('Serial Number')
                            ->nullable(),
                    ])->columns(3),

                Forms\Components\Section::make('Department & Assignment')
                    ->schema([
                        Forms\Components\TextInput::make('section')
                            ->nullable(),
                        Forms\Components\TextInput::make('dept_code')
                            ->label('Dept.')
                            ->nullable(),
                        Forms\Components\TextInput::make('employee_or_vehicle_name')
                            ->label('Employee / Vehicle Name')
                            ->nullable(),
                        Forms\Components\TextInput::make('assignment')
                            ->label('Assignment (Driver)')
                            ->nullable(),
                        Forms\Components\TextInput::make('location')
                            ->label('Current Location')
                            ->nullable(),
                    ])->columns(2),

                Forms\Components\Section::make('Additional Details')
                    ->schema([
                        Forms\Components\TextInput::make('sunpass_number')
                            ->label('Sunpass #')
                            ->nullable(),
                        Forms\Components\TextInput::make('als_license')
                            ->label('ALS License')
                            ->nullable(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('veh_number')
                    ->label('Veh #')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tag_number')
                    ->label('Tag #')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('section')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dept_code')
                    ->label('Dept.')
                    ->sortable(),
                Tables\Columns\TextColumn::make('make')
                    ->sortable(),
                Tables\Columns\TextColumn::make('model')
                    ->sortable(),
                Tables\Columns\TextColumn::make('year')
                    ->sortable(),
                Tables\Columns\TextColumn::make('employee_or_vehicle_name')
                    ->label('Employee/Vehicle')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('assignment')
                    ->label('Assignment')
                    ->searchable(),
                Tables\Columns\TextColumn::make('location')
                    ->label('Location')
                    ->searchable(),
                Tables\Columns\TextColumn::make('als_license')
                    ->label('ALS License')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('serial_number')
                    ->label('Serial #')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('sunpass_number')
                    ->label('Sunpass')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('section')
                    ->options(fn () => UnitMasterVehicle::whereNotNull('section')
                        ->distinct()
                        ->pluck('section', 'section')
                        ->toArray()),
                Tables\Filters\SelectFilter::make('dept_code')
                    ->label('Department')
                    ->options(fn () => UnitMasterVehicle::whereNotNull('dept_code')
                        ->distinct()
                        ->pluck('dept_code', 'dept_code')
                        ->toArray()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('veh_number');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUnitMasterVehicles::route('/'),
            'create' => Pages\CreateUnitMasterVehicle::route('/create'),
            'edit' => Pages\EditUnitMasterVehicle::route('/{record}/edit'),
        ];
    }
}
