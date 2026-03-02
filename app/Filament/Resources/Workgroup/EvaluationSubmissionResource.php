<?php

namespace App\Filament\Resources\Workgroup;

use App\Filament\Resources\Workgroup\Pages;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms;
use Filament\Forms\Form;

class EvaluationSubmissionResource extends Resource
{
    protected static ?string $model = EvaluationSubmission::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'Workgroup Management';

    protected static ?int $navigationSort = 9;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Submission Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('member.user.name')
                            ->label('Evaluator'),
                        Infolists\Components\TextEntry::make('candidateProduct.name')
                            ->label('Product'),
                        Infolists\Components\TextEntry::make('candidateProduct.manufacturer')
                            ->label('Manufacturer'),
                        Infolists\Components\TextEntry::make('candidateProduct.model')
                            ->label('Model'),
                        Infolists\Components\TextEntry::make('candidateProduct.category.name')
                            ->label('Category'),
                    ])
                    ->columns(3),
                Infolists\Components\Section::make('Status & Scoring')
                    ->schema([
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'draft' => 'gray',
                                'submitted' => 'success',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('submitted_at')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('total_score')
                            ->label('Score')
                            ->state(fn ($record) => $record->total_score !== null ? number_format($record->total_score, 2) . '%' : '-'),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('member.user.name')
                    ->searchable()
                    ->sortable()
                    ->label('Evaluator'),
                Tables\Columns\TextColumn::make('candidateProduct.name')
                    ->searchable()
                    ->sortable()
                    ->label('Product'),
                Tables\Columns\TextColumn::make('candidateProduct.manufacturer')
                    ->searchable()
                    ->label('Manufacturer'),
                Tables\Columns\TextColumn::make('candidateProduct.category.name')
                    ->searchable()
                    ->sortable()
                    ->label('Category'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'submitted' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('total_score')
                    ->label('Score')
                    ->formatStateUsing(fn ($state, $record) => $record->total_score !== null ? number_format($record->total_score, 2) . '%' : '-')
                    ->sortable(),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Submitted'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('workgroup_session_id')
                    ->label('Session')
                    ->options(fn () => WorkgroupSession::pluck('name', 'id'))
                    ->query(function (Builder $query, array $data) {
                        if ($data['value']) {
                            $query->whereHas('candidateProduct', function ($q) use ($data) {
                                $q->where('workgroup_session_id', $data['value']);
                            });
                        }
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'submitted' => 'Submitted',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEvaluationSubmissions::route('/'),
            'view' => Pages\ViewEvaluationSubmission::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasRole('logistics_admin');
    }

    public static function canView($record): bool
    {
        return auth()->user()->hasRole('logistics_admin');
    }
}
