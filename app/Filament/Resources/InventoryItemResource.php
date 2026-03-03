<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryItemResource\Pages;
use App\Models\InventoryItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationGroup = 'Inventory & Logistics';
    protected static ?string $navigationLabel = 'Supply Items';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
            Forms\Components\Section::make('Item Details')
            ->schema([
                Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
                Forms\Components\TextInput::make('sku')
                ->label('SKU')
                ->maxLength(255),
                Forms\Components\Select::make('category_id')
                ->relationship('category', 'name')
                ->required(),
                Forms\Components\TextInput::make('unit_label')
                ->label('Unit Label (e.g. "rolls", "box")')
                ->default('units')
                ->maxLength(255),
            ])->columns(2),

            Forms\Components\Section::make('Stock Settings')
            ->schema([
                Forms\Components\TextInput::make('par_quantity')
                ->label('Par Quantity (Expected On-Hand)')
                ->required()
                ->numeric()
                ->minValue(0),
                Forms\Components\TextInput::make('low_threshold')
                ->label('Low Stock Threshold')
                ->helperText('Override the default low stock alert level (50% of Par). Leave blank to use default.')
                ->numeric()
                ->minValue(0),
                Forms\Components\TextInput::make('unit_multiplier')
                ->label('Unit Multiplier')
                ->helperText('How many individual items are in one unit? Default is 1.')
                ->numeric()
                ->default(1)
                ->minValue(1),
                Forms\Components\Toggle::make('active')
                ->label('Active Item')
                ->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            Tables\Columns\TextColumn::make('name')
            ->searchable()
            ->sortable(),
            Tables\Columns\TextColumn::make('category.name')
            ->sortable()
            ->searchable(),
            Tables\Columns\TextColumn::make('par_quantity')
            ->label('Par')
            ->sortable(),
            Tables\Columns\TextColumn::make('low_threshold')
            ->label('Low Threshold')
            ->placeholder('Default (50%)')
            ->sortable(),
            Tables\Columns\IconColumn::make('active')
            ->boolean()
            ->sortable(),
        ])
            ->filters([
            Tables\Filters\SelectFilter::make('category')
            ->relationship('category', 'name'),
            Tables\Filters\TernaryFilter::make('active'),
        ])
            
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export')
                    ->color('gray')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exports([
                        ExcelExport::make('xlsx')
                            ->label('Export as Excel (.xlsx)')
                            ->fromTable()
                            ->withFilename('mbfd_inventory_items_' . date('Y-m-d')),
                        ExcelExport::make('csv')
                            ->label('Export as CSV (.csv)')
                            ->fromTable()
                            ->withFilename('mbfd_inventory_items_' . date('Y-m-d'))
                            ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                    ]),
            ])
            ->actions([
            Tables\Actions\EditAction::make(),
        ])
            ->bulkActions([
            Tables\Actions\BulkActionGroup::make([
                                    ExportBulkAction::make('export_selected')
                        ->label('Export Selected')
                        ->exports([
                            ExcelExport::make('xlsx')
                                ->label('Export as Excel (.xlsx)')
                                ->fromTable()
                                ->withFilename('mbfd_inventory_items_selected_' . date('Y-m-d')),
                            ExcelExport::make('csv')
                                ->label('Export as CSV (.csv)')
                                ->fromTable()
                                ->withFilename('mbfd_inventory_items_selected_' . date('Y-m-d'))
                                ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                        ]),
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
            'index' => Pages\ListInventoryItems::route('/'),
            'create' => Pages\CreateInventoryItem::route('/create'),
            'edit' => Pages\EditInventoryItem::route('/{record}/edit'),
        ];
    }
}
