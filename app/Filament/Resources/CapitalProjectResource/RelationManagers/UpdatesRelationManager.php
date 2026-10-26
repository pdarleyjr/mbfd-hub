<?php

namespace App\Filament\Resources\CapitalProjectResource\RelationManagers;

use App\Filament\Concerns\HasRelationExportActions;
use App\Services\Exports\ExportableTable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class UpdatesRelationManager extends RelationManager implements ExportableTable
{
    use HasRelationExportActions;

    protected static string $relationship = 'updates';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\RichEditor::make('update_text')
                    ->required()
                    ->columnSpanFull()
                    ->label('Update'),
                Forms\Components\Hidden::make('user_id')
                    ->default(fn () => Auth::id())
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return static::withExportActions($table
            ->recordTitleAttribute('update_text')
            ->columns([
                Tables\Columns\TextColumn::make('update_text')
                    ->html()
                    ->limit(100)
                    ->searchable()
                    ->label('Update'),
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
                Tables\Actions\DeleteAction::make()
                    ->visible(fn ($record) => $record->user_id === Auth::id()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn (): bool => Auth::user()?->can('admin.projects.manage') ?? false),
                ]),
            ])
            ->defaultSort('created_at', 'desc'));
    }
}
