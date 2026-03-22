<?php

namespace App\Filament\Resources\Workgroup;

use App\Filament\Resources\Workgroup\Pages;
use App\Models\EvaluationCategory;
use App\Support\Workgroups\UniversalEvaluationRubric;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EvaluationCategoryResource extends Resource
{
    protected static ?string $model = EvaluationCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Workgroup Management';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Category Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Category Name'),
                        Forms\Components\Textarea::make('description')
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('is_rankable')
                            ->label('Rankable')
                            ->helperText('Enable ranking for products in this category')
                            ->default(false),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Forms\Components\TextInput::make('display_order')
                            ->numeric()
                            ->label('Display Order')
                            ->default(0),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Rubric Configuration')
                    ->description('Configure how products in this category are evaluated')
                    ->schema([
                        Forms\Components\Select::make('assessment_profile')
                            ->label('Assessment Profile')
                            ->helperText('Determines which criteria are shown during evaluation')
                            ->options(UniversalEvaluationRubric::getAssessmentProfiles())
                            ->default('generic_apparatus'),
                        Forms\Components\Textarea::make('instructions_markdown')
                            ->label('Evaluator Instructions')
                            ->helperText('Custom instructions for evaluators (supports markdown)')
                            ->rows(4)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('finalists_limit')
                            ->numeric()
                            ->label('Finalists Limit')
                            ->helperText('Number of finalists to display (default: 2)')
                            ->default(2),
                        Forms\Components\Textarea::make('score_visibility_notes')
                            ->label('Score Visibility Notes')
                            ->helperText('Notes about how scores should be interpreted')
                            ->rows(2),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Category Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Name'),
                        Infolists\Components\TextEntry::make('description')
                            ->label('Description'),
                        Infolists\Components\IconEntry::make('is_rankable')
                            ->boolean()
                            ->label('Rankable'),
                        Infolists\Components\IconEntry::make('is_active')
                            ->boolean()
                            ->label('Active'),
                        Infolists\Components\TextEntry::make('display_order')
                            ->label('Display Order'),
                    ])
                    ->columns(3),
                Infolists\Components\Section::make('Rubric Configuration')
                    ->schema([
                        Infolists\Components\TextEntry::make('assessment_profile')
                            ->label('Assessment Profile')
                            ->state(fn ($record) => $record->assessment_profile_label ?? 'Generic'),
                        Infolists\Components\TextEntry::make('finalists_limit')
                            ->label('Finalists Limit'),
                    ])
                    ->columns(2),
                Infolists\Components\Section::make('Statistics')
                    ->schema([
                        Infolists\Components\TextEntry::make('products_count')
                            ->label('Products')
                            ->state(fn ($record) => $record->candidateProducts()->count()),
                    ])
                    ->columns(1),
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
                Tables\Columns\IconColumn::make('is_rankable')
                    ->boolean()
                    ->label('Rankable'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                Tables\Columns\TextColumn::make('assessment_profile')
                    ->label('Profile')
                    ->formatStateUsing(fn ($state) => UniversalEvaluationRubric::getAssessmentProfiles()[$state] ?? $state ?? 'Generic')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('products_count')
                    ->counts('candidateProducts')
                    ->label('Products'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('active')
                    ->query(fn (Builder $query) => $query->where('is_active', true))
                    ->label('Active Only'),
                Tables\Filters\Filter::make('rankable')
                    ->query(fn (Builder $query) => $query->where('is_rankable', true))
                    ->label('Rankable Only'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ListEvaluationCategories::route('/'),
            'create' => Pages\CreateEvaluationCategory::route('/create'),
            'view' => Pages\ViewEvaluationCategory::route('/{record}'),
            'edit' => Pages\EditEvaluationCategory::route('/{record}/edit'),
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
