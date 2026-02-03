<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CapitalProjectResource\Pages;
use App\Filament\Resources\CapitalProjectResource\RelationManagers;
use App\Models\CapitalProject;
use App\Enums\ProjectStatus;
use App\Enums\ProjectPriority;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CapitalProjectResource extends Resource
{
    protected static ?string $model = CapitalProject::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    
    protected static ?string $navigationGroup = 'Projects';
    
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Project Information')
                    ->schema([
                        Forms\Components\TextInput::make('project_number')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('budget_amount')
                            ->required()
                            ->numeric()
                            ->prefix('$')
                            ->default(0),
                        Forms\Components\Select::make('status')
                            ->required()
                            ->options(ProjectStatus::class)
                            ->default('pending')
                            ->native(false),
                        Forms\Components\Select::make('priority')
                            ->required()
                            ->options(ProjectPriority::class)
                            ->default('medium')
                            ->native(false),
                        Forms\Components\Select::make('station_id')
                            ->relationship('station', 'name')
                            ->searchable()
                            ->preload()
                            ->label('Station')
                            ->placeholder('Select Station'),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Timeline')
                    ->schema([
                        Forms\Components\DatePicker::make('start_date')
                            ->displayFormat('M d, Y')
                            ->native(false),
                        Forms\Components\DatePicker::make('target_completion_date')
                            ->displayFormat('M d, Y')
                            ->native(false),
                        Forms\Components\DatePicker::make('actual_completion_date')
                            ->displayFormat('M d, Y')
                            ->native(false)
                            ->disabled(fn ($get) => $get('status') !== 'completed'),
                    ])
                    ->columns(3),
                    
                Forms\Components\Section::make('AI Insights')
                    ->schema([
                        Forms\Components\Placeholder::make('ai_analysis')
                            ->label('AI Analysis Results')
                            ->content(function ($record) {
                                if (!$record || !$record->ai_priority_rank) {
                                    return 'No AI analysis available yet.';
                                }
                                
                                return "Rank: {$record->ai_priority_rank} | Score: {$record->ai_priority_score}\n\n" .
                                       "Reasoning: {$record->ai_reasoning}\n\n" .
                                       "Last Analysis: " . ($record->last_ai_analysis ? $record->last_ai_analysis->format('M d, Y H:i') : 'N/A');
                            })
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => $record && $record->ai_priority_rank !== null),
                    
                Forms\Components\Section::make('Notes')
                    ->schema([
                        Forms\Components\RichEditor::make('notes')
                            ->columnSpanFull(),
                    ]),
                    
                Forms\Components\Section::make('Progress & Attachments')
                    ->schema([
                        Forms\Components\TextInput::make('percent_complete')
                            ->label('Progress (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->helperText('Project completion percentage (0–100).')
                            ->nullable(),
                        Forms\Components\FileUpload::make('attachments')
                            ->label('Project Files')
                            ->multiple()
                            ->downloadable()
                            ->openable()
                            ->disk('public')
                            ->directory('capital-projects')
                            ->preserveFilenames()
                            ->maxSize(25600)
                            ->helperText('Upload meeting docs, quotes, specs, invoices, photos.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project_number')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('station.name')
                    ->label('Station')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('budget_amount')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state): string => match ($state?->value ?? $state) {
                        'pending' => 'warning',
                        'in_progress' => 'info',
                        'completed' => 'success',
                        'on_hold' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->color(fn ($state): string => match ($state?->value ?? $state) {
                        'low' => 'gray',
                        'medium' => 'info',
                        'high' => 'warning',
                        'critical' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('target_completion_date')
                    ->date('M d, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('completion_percentage')
                    ->label('Progress')
                    ->formatStateUsing(fn ($state) => ($state ?? 0) . '%')
                    ->color(fn ($state) => match (true) {
                        ($state ?? 0) >= 75 => 'success',
                        ($state ?? 0) >= 50 => 'warning',
                        ($state ?? 0) >= 25 => 'info',
                        default => 'danger',
                    })
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('station')
                    ->relationship('station', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Station'),
                Tables\Filters\SelectFilter::make('status')
                    ->options(ProjectStatus::class)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('priority')
                    ->options(ProjectPriority::class)
                    ->multiple(),
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
    
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Project Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('project_number'),
                        Infolists\Components\TextEntry::make('name'),
                        Infolists\Components\TextEntry::make('description')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('budget_amount')
                            ->money('USD'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn ($state): string => match ($state?->value ?? $state) {
                                'pending' => 'warning',
                                'in_progress' => 'info',
                                'completed' => 'success',
                                'on_hold' => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('priority')
                            ->badge()
                            ->color(fn ($state): string => match ($state?->value ?? $state) {
                                'low' => 'gray',
                                'medium' => 'info',
                                'high' => 'warning',
                                'critical' => 'danger',
                                default => 'gray',
                            }),
                    ])
                    ->columns(2),
                    
                Infolists\Components\Section::make('Timeline')
                    ->schema([
                        Infolists\Components\TextEntry::make('start_date')
                            ->date('M d, Y'),
                        Infolists\Components\TextEntry::make('target_completion_date')
                            ->date('M d, Y'),
                        Infolists\Components\TextEntry::make('actual_completion_date')
                            ->date('M d, Y'),
                        Infolists\Components\TextEntry::make('completion_percentage')
                            ->suffix('%'),
                    ])
                    ->columns(4),
                    
                Infolists\Components\Section::make('AI Analysis')
                    ->schema([
                        Infolists\Components\TextEntry::make('ai_priority_rank')
                            ->label('AI Priority Rank'),
                        Infolists\Components\TextEntry::make('ai_priority_score')
                            ->label('AI Priority Score'),
                        Infolists\Components\TextEntry::make('ai_reasoning')
                            ->label('AI Reasoning')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('last_ai_analysis')
                            ->label('Last AI Analysis')
                            ->dateTime('M d, Y H:i'),
                    ])
                    ->columns(2)
                    ->visible(fn ($record) => $record->ai_priority_rank !== null),
                    
                Infolists\Components\Section::make('Notes')
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->html()
                            ->columnSpanFull(),
                    ]),
                    
                Infolists\Components\Section::make('Progress')
                    ->schema([
                        Infolists\Components\TextEntry::make('percent_complete')
                            ->label('Completion')
                            ->suffix('%')
                            ->badge()
                            ->color(fn ($state) => match (true) {
                                ($state ?? 0) >= 90 => 'success',
                                ($state ?? 0) >= 50 => 'warning',
                                default => 'danger',
                            }),
                    ]),
                    
                Infolists\Components\Section::make('Attachments')
                    ->schema([
                        Infolists\Components\TextEntry::make('attachments')
                            ->label('Project Files')
                            ->formatStateUsing(function ($state, $record) {
                                if (empty($record->attachments)) {
                                    return 'No files attached.';
                                }
                                $links = [];
                                foreach ($record->attachments as $path) {
                                    $filename = basename($path);
                                    $url = asset('storage/' . $path);
                                    $links[] = "<a href=\"{$url}\" target=\"_blank\" class=\"text-primary-600 hover:underline\">📄 {$filename}</a>";
                                }
                                return implode('<br>', $links);
                            })
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->hidden(fn ($record) => empty($record->attachments)),
                    
                Infolists\Components\Section::make('Related Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('milestones_count')
                            ->label('Total Milestones')
                            ->state(fn ($record) => $record->milestones()->count()),
                        Infolists\Components\TextEntry::make('updates_count')
                            ->label('Total Updates')
                            ->state(fn ($record) => $record->updates()->count()),
                    ])
                    ->columns(2),
            ]);
    }

    public static function getRelations(): array
    {
        return [
//            RelationManagers\MilestonesRelationManager::class,
            RelationManagers\UpdatesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCapitalProjects::route('/'),
            'create' => Pages\CreateCapitalProject::route('/create'),
            'view' => Pages\ViewCapitalProject::route('/{record}'),
            'edit' => Pages\EditCapitalProject::route('/{record}/edit'),
        ];
    }
}
