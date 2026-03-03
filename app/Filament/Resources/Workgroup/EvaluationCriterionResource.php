<?php

namespace App\Filament\Resources\Workgroup;

use App\Filament\Resources\Workgroup\Pages;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class EvaluationCriterionResource extends Resource
{
    protected static ?string $model = EvaluationCriterion::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Workgroup Management';

    protected static ?int $navigationSort = 7;

    /** @deprecated Criteria are no longer used - using Universal Evaluation Rubric */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Criterion Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Criterion Name'),
                        Forms\Components\Textarea::make('description')
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('template_id')
                            ->label('Template')
                            ->options(fn () => EvaluationTemplate::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Forms\Components\TextInput::make('max_score')
                            ->numeric()
                            ->label('Max Score')
                            ->default(10)
                            ->minValue(1),
                        Forms\Components\TextInput::make('weight')
                            ->numeric()
                            ->label('Weight')
                            ->default(1.0)
                            ->step(0.1)
                            ->minValue(0.1),
                        Forms\Components\TextInput::make('display_order')
                            ->numeric()
                            ->label('Display Order')
                            ->default(0),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_order')
                    ->sortable()
                    ->label('Order'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label('Name'),
                Tables\Columns\TextColumn::make('template.name')
                    ->searchable()
                    ->sortable()
                    ->label('Template'),
                Tables\Columns\TextColumn::make('max_score')
                    ->sortable()
                    ->label('Max Score'),
                Tables\Columns\TextColumn::make('weight')
                    ->sortable()
                    ->label('Weight'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('template_id')
                    ->label('Template')
                    ->options(fn () => EvaluationTemplate::pluck('name', 'id')),
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
                            ->withFilename('mbfd_wg_eval_criteria_' . date('Y-m-d')),
                        ExcelExport::make('csv')
                            ->label('Export as CSV (.csv)')
                            ->fromTable()
                            ->withFilename('mbfd_wg_eval_criteria_' . date('Y-m-d'))
                            ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                                        ExportBulkAction::make('export_selected')
                        ->label('Export Selected')
                        ->exports([
                            ExcelExport::make('xlsx')
                                ->label('Export as Excel (.xlsx)')
                                ->fromTable()
                                ->withFilename('mbfd_wg_eval_criteria_selected_' . date('Y-m-d')),
                            ExcelExport::make('csv')
                                ->label('Export as CSV (.csv)')
                                ->fromTable()
                                ->withFilename('mbfd_wg_eval_criteria_selected_' . date('Y-m-d'))
                                ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                        ]),
Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('display_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEvaluationCriteria::route('/'),
            'create' => Pages\CreateEvaluationCriterion::route('/create'),
            'edit' => Pages\EditEvaluationCriterion::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public static function canEdit($record): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }
}
