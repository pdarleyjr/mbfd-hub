<?php

namespace App\Filament\Resources\Workgroup;

use App\Filament\Resources\Workgroup\Concerns\ResolvesWorkgroupAccess;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EvaluationCriterionResource extends Resource
{
    use ResolvesWorkgroupAccess;

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
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
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
        $user = self::currentWorkgroupUser();

        return $user !== null && self::workgroupAccess()->isGlobalViewer($user);
    }

    public static function canCreate(): bool
    {
        return self::currentWorkgroupUser()?->hasRole('super_admin') ?? false;
    }

    public static function canEdit($record): bool
    {
        return self::canCreate();
    }

    public static function canDelete($record): bool
    {
        return self::canCreate();
    }

    public static function canView($record): bool
    {
        return self::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return self::canViewAny()
            ? parent::getEloquentQuery()
            : parent::getEloquentQuery()->whereRaw('1 = 0');
    }
}
