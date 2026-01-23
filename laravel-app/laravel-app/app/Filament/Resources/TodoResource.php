<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TodoResource\Pages;
use App\Models\Todo;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TodoResource extends Resource
{
    protected static ?string $model = Todo::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_completed')
                    ->label('Completed')
                    ->default(false),
                Forms\Components\Hidden::make('created_by_user_id')
                    ->default(auth()->id()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\IconColumn::make('is_completed')
                    ->boolean()
                    ->label('Done')
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->sortable(),
                Tables\Columns\TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_completed')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Completed')
                    ->falseLabel('Pending'),
            ])
            ->actions([
                Tables\Actions\Action::make('toggle')
                    ->icon(fn (Todo $record) => $record->is_completed ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (Todo $record) => $record->is_completed ? 'warning' : 'success')
                    ->action(function (Todo $record) {
                        $record->update([
                            'is_completed' => !$record->is_completed,
                            'completed_at' => !$record->is_completed ? now() : null,
                        ]);
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->reorderable('sort');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTodos::route('/'),
            'create' => Pages\CreateTodo::route('/create'),
            'edit' => Pages\EditTodo::route('/{record}/edit'),
        ];
    }
}
