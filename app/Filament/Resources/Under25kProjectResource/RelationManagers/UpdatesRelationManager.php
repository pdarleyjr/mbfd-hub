<?php

namespace App\Filament\Resources\Under25kProjectResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class UpdatesRelationManager extends RelationManager
{
    protected static string $relationship = 'updates';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->label('Update Title'),
                Forms\Components\RichEditor::make('body')
                    ->required()
                    ->columnSpanFull()
                    ->label('Update Body'),
                Forms\Components\TextInput::make('percent_complete_snapshot')
                    ->label('Progress Snapshot (%)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->helperText('Capture the current progress percentage at the time of this update.')
                    ->nullable(),
                Forms\Components\Hidden::make('user_id')
                    ->default(fn () => Auth::id())
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->label('Title'),
                Tables\Columns\TextColumn::make('body')
                    ->html()
                    ->limit(100)
                    ->searchable()
                    ->label('Update'),
                Tables\Columns\TextColumn::make('percent_complete_snapshot')
                    ->formatStateUsing(fn ($state) => $state ? $state . '%' : 'N/A')
                    ->label('Progress')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Posted By')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Posted')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['user_id'] = Auth::id();
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn ($record) => $record->user_id === Auth::id()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => Auth::user()?->isAdmin ?? false),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
