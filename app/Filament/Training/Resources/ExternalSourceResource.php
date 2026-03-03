<?php

namespace App\Filament\Training\Resources;

use App\Filament\Training\Resources\ExternalSourceResource\Pages;
use App\Models\ExternalSource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class ExternalSourceResource extends Resource
{
    protected static ?string $model = ExternalSource::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'External Tools';

    protected static ?string $navigationLabel = 'External Sources';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user && $user->hasRole('training_admin');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('division')->default('training'),
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('provider')
                ->options(['baserow' => 'Baserow'])
                ->default('baserow')
                ->required(),
            Forms\Components\TextInput::make('base_url')
                ->label('Base URL')
                ->url()
                ->required()
                ->default('https://baserow.mbfdhub.com'),
            Forms\Components\TextInput::make('token')
                ->label('API Token')
                ->password()
                ->dehydrated(fn ($state) => filled($state))
                ->helperText('Token is encrypted at rest. Leave blank to keep existing.'),
            Forms\Components\TextInput::make('token_hint')
                ->label('Token Hint')
                ->placeholder('e.g., env: BASEROW_TRAINING_TOKEN')
                ->maxLength(255),
            Forms\Components\Select::make('status')
                ->options(['active' => 'Active', 'inactive' => 'Inactive'])
                ->default('active')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('provider')->badge(),
                Tables\Columns\TextColumn::make('base_url')->limit(40),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors(['success' => 'active', 'danger' => 'inactive']),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('name')
            
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export')
                    ->color('gray')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exports([
                        ExcelExport::make('xlsx')
                            ->label('Export as Excel (.xlsx)')
                            ->fromTable()
                            ->withFilename('mbfd_training_sources_' . date('Y-m-d')),
                        ExcelExport::make('csv')
                            ->label('Export as CSV (.csv)')
                            ->fromTable()
                            ->withFilename('mbfd_training_sources_' . date('Y-m-d'))
                            ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                    ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                                        ExportBulkAction::make('export_selected')
                        ->label('Export Selected')
                        ->exports([
                            ExcelExport::make('xlsx')
                                ->label('Export as Excel (.xlsx)')
                                ->fromTable()
                                ->withFilename('mbfd_training_sources_selected_' . date('Y-m-d')),
                            ExcelExport::make('csv')
                                ->label('Export as CSV (.csv)')
                                ->fromTable()
                                ->withFilename('mbfd_training_sources_selected_' . date('Y-m-d'))
                                ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                        ]),
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExternalSources::route('/'),
            'create' => Pages\CreateExternalSource::route('/create'),
            'edit' => Pages\EditExternalSource::route('/{record}/edit'),
        ];
    }
}
