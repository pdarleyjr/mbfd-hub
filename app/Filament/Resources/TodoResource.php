<?php

namespace App\Filament\Resources;

use App\Enums\StaffMember;
use App\Filament\Resources\TodoResource\Pages;
use App\Models\Todo;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TodoResource extends Resource
{
    protected static ?string $model = Todo::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'Projects';

    protected static ?string $navigationLabel = 'Todo List';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Forms\Components\RichEditor::make('description')
                    ->columnSpanFull(),
                Forms\Components\FileUpload::make('attachment')
                    ->label('Attachment')
                    ->disk('public')
                    ->directory('todo_attachments')
                    ->image()
                    ->maxSize(5120),
                Forms\Components\Select::make('assigned_to')
                    ->label('Assigned To')
                    ->relationship('assignedTo', 'name')
                    ->searchable()
                    ->preload(),
                Forms\Components\Hidden::make('created_by')
                    ->default(auth()->id()),
                Forms\Components\Toggle::make('is_completed')
                    ->label('Completed'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\IconColumn::make('is_completed')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('assignedTo.name')
                    ->label('Assigned To')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->searchable()
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
                    ->label('Completed')
                    ->placeholder('All')
                    ->trueLabel('Completed')
                    ->falseLabel('Pending'),
                Tables\Filters\SelectFilter::make('assigned_to')
                    ->label('Assigned To')
                    ->relationship('assignedTo', 'name')
                    ->multiple()
                    ->preload(),
                Tables\Filters\SelectFilter::make('created_by')
                    ->label('Created By')
                    ->relationship('createdBy', 'name')
                    ->multiple()
                    ->preload(),
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
            ->defaultSort('is_completed', 'asc')
            ->defaultSort('created_at', 'desc')
            ->filtersTabs([
                'all' => Tables\Filters\Tab::make('All'),
                'assigned_to_me' => Tables\Filters\Tab::make('Assigned to me')
                    ->modifyQueryUsing(fn ($query) => $query->where('assigned_to', auth()->id())),
                'created_by_me' => Tables\Filters\Tab::make('Created by me')
                    ->modifyQueryUsing(fn ($query) => $query->where('created_by', auth()->id())),
            ]);
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
