<?php

namespace App\Filament\Resources\Workgroup;

use App\Filament\Resources\Workgroup\Pages;
use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\WorkgroupSession;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CandidateProductResource extends Resource
{
    protected static ?string $model = CandidateProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Workgroup Management';

    protected static ?int $navigationSort = 8;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Product Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Product Name'),
                        Forms\Components\TextInput::make('manufacturer')
                            ->maxLength(255)
                            ->label('Manufacturer'),
                        Forms\Components\TextInput::make('model')
                            ->maxLength(255)
                            ->label('Model'),
                        Forms\Components\Textarea::make('description')
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Classification')
                    ->schema([
                        Forms\Components\Select::make('workgroup_session_id')
                            ->label('Session')
                            ->options(fn () => WorkgroupSession::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('category_id')
                            ->label('Category')
                            ->options(fn () => EvaluationCategory::orderBy('display_order')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Product Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Name'),
                        Infolists\Components\TextEntry::make('manufacturer')
                            ->label('Manufacturer'),
                        Infolists\Components\TextEntry::make('model')
                            ->label('Model'),
                    ])
                    ->columns(3),
                Infolists\Components\Section::make('Classification')
                    ->schema([
                        Infolists\Components\TextEntry::make('session.name')
                            ->label('Session'),
                        Infolists\Components\TextEntry::make('category.name')
                            ->label('Category'),
                    ])
                    ->columns(2),
                Infolists\Components\Section::make('Statistics')
                    ->schema([
                        Infolists\Components\TextEntry::make('submissions_count')
                            ->label('Submissions')
                            ->state(fn ($record) => $record->submissions()->count()),
                    ])
                    ->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label('Name'),
                Tables\Columns\TextColumn::make('manufacturer')
                    ->searchable()
                    ->label('Manufacturer'),
                Tables\Columns\TextColumn::make('model')
                    ->searchable()
                    ->label('Model'),
                Tables\Columns\TextColumn::make('category.name')
                    ->searchable()
                    ->sortable()
                    ->label('Category'),
                Tables\Columns\TextColumn::make('session.name')
                    ->searchable()
                    ->sortable()
                    ->label('Session'),
                Tables\Columns\TextColumn::make('submissions_count')
                    ->counts('submissions')
                    ->label('Submissions'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('workgroup_session_id')
                    ->label('Session')
                    ->options(fn () => WorkgroupSession::pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Category')
                    ->options(fn () => EvaluationCategory::pluck('name', 'id')),
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
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCandidateProducts::route('/'),
            'create' => Pages\CreateCandidateProduct::route('/create'),
            'view' => Pages\ViewCandidateProduct::route('/{record}'),
            'edit' => Pages\EditCandidateProduct::route('/{record}/edit'),
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
