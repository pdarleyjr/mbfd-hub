<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\ShopWorkResource\Pages;
use App\Models\ShopWork;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ShopWorkResource extends Resource
{
    use HasExportActions;

    /** The database stores status as an enum; these are the only values it accepts. */
    public const STATUSES = [
        'Pending' => 'Pending',
        'In Progress' => 'In Progress',
        'Waiting for Parts' => 'Waiting for Parts',
        'Completed' => 'Completed',
        'Cancelled' => 'Cancelled',
    ];

    protected static ?int $navigationSort = 50;

    protected static ?string $model = ShopWork::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Logistics';

    protected static ?string $navigationLabel = 'Shop Work';

    protected static ?string $modelLabel = 'Shop Need';

    protected static ?string $pluralModelLabel = 'Shop Needs';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('project_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\Select::make('apparatus_id')
                    ->label('Apparatus')
                    ->relationship('apparatus', 'designation')
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('status')
                    ->options(self::STATUSES)
                    ->in(array_keys(self::STATUSES))
                    ->required()
                    ->default('Pending'),
                Forms\Components\Textarea::make('parts_list')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('estimated_cost')
                    ->numeric(),
                Forms\Components\TextInput::make('actual_cost')
                    ->numeric(),
                Forms\Components\DatePicker::make('started_date'),
                Forms\Components\DatePicker::make('completed_date'),
                Forms\Components\TextInput::make('assigned_to')
                    ->maxLength(255),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions($table
            ->columns([
                Tables\Columns\TextColumn::make('project_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('apparatus.designation')
                    ->label('Apparatus')
                    ->placeholder('Not tied to a unit')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('estimated_cost')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('actual_cost')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('started_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('completed_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('assigned_to')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(self::STATUSES),
                Tables\Filters\TernaryFilter::make('completed')
                    ->label('Completed')
                    ->placeholder('All shop needs')
                    ->trueLabel('Completed')
                    ->falseLabel('Not completed yet')
                    ->nullable()
                    ->attribute('completed_date'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]));
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
            'index' => Pages\ListShopWorks::route('/'),
            'create' => Pages\CreateShopWork::route('/create'),
            'edit' => Pages\EditShopWork::route('/{record}/edit'),
        ];
    }
}
