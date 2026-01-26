<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopWorkResource\Pages;
use App\Filament\Resources\ShopWorkResource\RelationManagers;
use App\Models\ShopWork;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ShopWorkResource extends Resource
{
    protected static ?string $model = ShopWork::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';
    
    protected static ?string $navigationLabel = 'Shop Needs';
    
    protected static ?string $modelLabel = 'Shop Need';
    
    protected static ?string $pluralModelLabel = 'Shop Needs';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Item Details')
                    ->schema([
                        Forms\Components\TextInput::make('project_name')
                            ->label('Item Name')
                            ->maxLength(255),
                        Forms\Components\Select::make('category')
                            ->options([
                                'Lifting Equipment' => 'Lifting Equipment',
                                'Jacks & Stands' => 'Jacks & Stands',
                                'Tools & Maintenance Equipment' => 'Tools & Maintenance Equipment',
                            ]),
                        Forms\Components\TextInput::make('quantity')
                            ->numeric()
                            ->default(1),
                        Forms\Components\Textarea::make('description')
                            ->columnSpanFull(),
                    ])->columns(3),
                    
                Forms\Components\Section::make('Priority & Status')
                    ->schema([
                        Forms\Components\Select::make('priority')
                            ->options([
                                1 => '1 - Highest Priority',
                                2 => '2 - High Priority',
                                3 => '3 - Medium Priority',
                                4 => '4 - Low Priority',
                                5 => '5 - Lowest Priority',
                            ])
                            ->default(3),
                        Forms\Components\Select::make('status')
                            ->options([
                                'Pending' => 'Pending',
                                'In Progress' => 'In Progress',
                                'Waiting for Parts' => 'Waiting for Parts',
                                'Completed' => 'Completed',
                                'Cancelled' => 'Cancelled',
                            ])
                            ->default('Pending'),
                    ])->columns(2),
                    
                Forms\Components\Section::make('Cost Information')
                    ->schema([
                        Forms\Components\TextInput::make('estimated_cost')
                            ->label('Estimated Cost ($)')
                            ->numeric()
                            ->prefix('$'),
                        Forms\Components\TextInput::make('actual_cost')
                            ->label('Actual Cost ($)')
                            ->numeric()
                            ->prefix('$'),
                    ])->columns(2),
                    
                Forms\Components\Section::make('Additional Info')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->columnSpanFull(),
                    ])->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('priority', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->color(fn (int $state): string => match ($state) {
                        1 => 'danger',
                        2 => 'warning',
                        3 => 'info',
                        4 => 'gray',
                        5 => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('project_name')
                    ->label('Item Name')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->color('primary')
                    ->searchable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('estimated_cost')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Pending' => 'warning',
                        'In Progress' => 'info',
                        'Waiting for Parts' => 'gray',
                        'Completed' => 'success',
                        'Cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'Pending' => 'Pending',
                        'In Progress' => 'In Progress',
                        'Waiting for Parts' => 'Waiting for Parts',
                        'Completed' => 'Completed',
                        'Cancelled' => 'Cancelled',
                    ]),
                Tables\Filters\SelectFilter::make('category')
                    ->options([
                        'Lifting Equipment' => 'Lifting Equipment',
                        'Jacks & Stands' => 'Jacks & Stands',
                        'Tools & Maintenance Equipment' => 'Tools & Maintenance Equipment',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('markCompleted')
                    ->label('Complete')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ShopWork $record): bool => $record->status !== 'Completed')
                    ->action(fn (ShopWork $record) => $record->update([
                        'status' => 'Completed',
                        'completed_date' => now(),
                    ])),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
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
            'index' => Pages\ListShopWorks::route('/'),
            'create' => Pages\CreateShopWork::route('/create'),
            'edit' => Pages\EditShopWork::route('/{record}/edit'),
        ];
    }
}
