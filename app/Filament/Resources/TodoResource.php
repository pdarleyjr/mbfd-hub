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
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

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
                Forms\Components\Select::make('assigned_to')
                    ->label('Assigned To')
                    ->multiple()
                    ->options(User::pluck('name', 'id'))
                    ->searchable()
                    ->preload(),
                Forms\Components\Hidden::make('created_by')
                    ->default(auth()->id()),
                Forms\Components\Toggle::make('is_completed')
                    ->label('Completed'),
                Forms\Components\FileUpload::make('attachments')
                    ->multiple()
                    ->directory('todo-attachments')
                    ->downloadable()
                    ->openable()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Split::make([
                    Tables\Columns\ToggleColumn::make('is_completed')
                        ->label('Status')
                        ->onIcon('heroicon-o-check-circle')
                        ->offIcon('heroicon-o-x-circle')
                        ->onColor('success')
                        ->offColor('gray')
                        ->afterStateUpdated(function ($record, $state) {
                            $record->completed_at = $state ? now() : null;
                            $record->save();
                        }),
                    TextColumn::make('title')
                        ->weight('medium')
                        ->searchable()
                        ->sortable()
                        ->description(function ($record) {
                            $desc = Str::of(strip_tags($record->description ?? ''))->squish()->limit(90);
                            $assigned = $record->assignees->pluck('name')->filter()->join(', ');
                            $meta = collect([
                                $assigned ? "👤 {$assigned}" : null,
                                $record->is_completed ? '✅ Completed' : null,
                            ])->filter()->join(' • ');

                            return trim($desc . ($meta ? "\n{$meta}" : ''));
                        }),
                    TextColumn::make('assignee_names')
                        ->label('Assigned To')
                        ->badge()
                        ->color('primary')
                        ->visibleFrom('md'),
                    TextColumn::make('createdBy.name')
                        ->label('Created By')
                        ->searchable()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true)
                        ->visibleFrom('md'),
                    TextColumn::make('completed_at')
                        ->dateTime()
                        ->sortable()
                        ->toggleable()
                        ->visibleFrom('md'),
                    TextColumn::make('created_at')
                        ->dateTime()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true)
                        ->visibleFrom('md'),
                ])->from('md'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_completed')
                    ->label('Completed')
                    ->placeholder('All')
                    ->trueLabel('Completed')
                    ->falseLabel('Pending'),
                Tables\Filters\SelectFilter::make('created_by')
                    ->label('Created By')
                    ->relationship('createdBy', 'name')
                    ->multiple()
                    ->preload(),
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
            ->defaultSort('is_completed', 'asc')
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTodos::route('/'),
            'create' => Pages\CreateTodo::route('/create'),
            'view' => Pages\ViewTodo::route('/{record}'),
            'edit' => Pages\EditTodo::route('/{record}/edit'),
        ];
    }
}
