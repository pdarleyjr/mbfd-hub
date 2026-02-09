<?php

namespace App\Filament\Training\Resources;

use App\Filament\Training\Resources\TrainingTodoResource\Pages;
use App\Models\Training\TrainingTodo;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TrainingTodoResource extends Resource
{
    protected static ?string $model = TrainingTodo::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'Training Tasks';

    protected static ?string $navigationLabel = 'Training Todos';

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
                Forms\Components\Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                    ])
                    ->default('pending')
                    ->required(),
                Forms\Components\Select::make('priority')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                        'urgent' => 'Urgent',
                    ])
                    ->default('medium')
                    ->required(),
                Forms\Components\Select::make('assigned_to')
                    ->label('Assigned To')
                    ->multiple()
                    ->options(User::pluck('name', 'id'))
                    ->searchable()
                    ->preload(),
                Forms\Components\Hidden::make('created_by')
                    ->default(auth()->id()),
                Forms\Components\FileUpload::make('attachments')
                    ->multiple()
                    ->directory('training-todo-attachments')
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
                    TextColumn::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'completed' => 'success',
                            'in_progress' => 'warning',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'in_progress' => 'In Progress',
                            default => ucfirst($state),
                        })
                        ->sortable(),
                    TextColumn::make('priority')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'urgent' => 'danger',
                            'high' => 'warning',
                            'medium' => 'info',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (string $state): string => ucfirst($state))
                        ->sortable(),
                    TextColumn::make('title')
                        ->weight('medium')
                        ->searchable()
                        ->sortable()
                        ->description(function ($record) {
                            $desc = Str::of(strip_tags($record->description ?? ''))->squish()->limit(90);
                            $assigned = $record->assignees->pluck('name')->filter()->join(', ');
                            $meta = $assigned ? "👤 {$assigned}" : null;
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
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                    ]),
                Tables\Filters\SelectFilter::make('priority')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                        'urgent' => 'Urgent',
                    ]),
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
            ->defaultSort('status', 'asc')
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrainingTodos::route('/'),
            'create' => Pages\CreateTrainingTodo::route('/create'),
            'view' => Pages\ViewTrainingTodo::route('/{record}'),
            'edit' => Pages\EditTrainingTodo::route('/{record}/edit'),
        ];
    }
}
