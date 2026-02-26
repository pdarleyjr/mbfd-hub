<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Under25kProjectResource\Pages;
use App\Filament\Resources\Under25kProjectResource\RelationManagers;
use App\Models\Under25kProject;
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

class Under25kProjectResource extends Resource
{
    protected static ?string $model = Under25kProject::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    
    protected static ?string $navigationGroup = 'Project Management';
    
    protected static ?string $navigationLabel = 'Under 25k';
    
    protected static ?string $slug = 'under-25k';
    
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Core Project Information')
                    ->schema([
                        Forms\Components\TextInput::make('project_number')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->label('Project Number'),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->label('Project Name'),
                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull()
                            ->label('Description'),
                        Forms\Components\TextInput::make('project_manager')
                            ->maxLength(255)
                            ->label('Project Manager'),
                        Forms\Components\Select::make('station_id')
                            ->relationship('station', 'station_number')
                            ->searchable()
                            ->preload()
                            ->label('Station')
                            ->placeholder('Select Station'),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Financial Information')
                    ->schema([
                        Forms\Components\TextInput::make('budget_amount')
                            ->required()
                            ->numeric()
                            ->prefix('$')
                            ->default(0)
                            ->label('Budget Amount'),
                        Forms\Components\TextInput::make('spend_amount')
                            ->numeric()
                            ->prefix('$')
                            ->default(0)
                            ->label('Spend Amount'),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Timeline')
                    ->schema([
                        Forms\Components\DatePicker::make('start_date')
                            ->displayFormat('M d, Y')
                            ->native(false)
                            ->label('Start Date'),
                        Forms\Components\DatePicker::make('target_completion_date')
                            ->displayFormat('M d, Y')
                            ->native(false)
                            ->label('Target Completion Date'),
                        Forms\Components\DatePicker::make('actual_completion_date')
                            ->displayFormat('M d, Y')
                            ->native(false)
                            ->disabled(fn ($get) => $get('status') !== 'Completed')
                            ->label('Actual Completion Date'),
                    ])
                    ->columns(3),
                    
                Forms\Components\Section::make('Status & Priority')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->required()
                            ->options(ProjectStatus::class)
                            ->default('pending')
                            ->native(false)
                            ->label('Status'),
                        Forms\Components\Select::make('priority')
                            ->required()
                            ->options(ProjectPriority::class)
                            ->default('medium')
                            ->native(false)
                            ->label('Priority'),
                    ])
                    ->columns(2),
                    
                Forms\Components\Section::make('Progress')
                    ->schema([
                        Forms\Components\TextInput::make('percent_complete')
                            ->label('Progress (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->helperText('Project completion percentage (0–100).')
                            ->nullable(),
                    ])
                    ->columns(1),
                    
                Forms\Components\Section::make('Notes')
                    ->schema([
                        Forms\Components\RichEditor::make('notes')
                            ->columnSpanFull()
                            ->label('Public Notes'),
                        Forms\Components\Textarea::make('internal_notes')
                            ->rows(3)
                            ->columnSpanFull()
                            ->label('Internal Notes')
                            ->helperText('Only visible to administrators'),
                    ]),
                    
                Forms\Components\Section::make('Attachments')
                    ->schema([
                        Forms\Components\FileUpload::make('attachments')
                            ->label('Project Files')
                            ->multiple()
                            ->downloadable()
                            ->openable()
                            ->disk('public')
                            ->directory('under-25k-projects')
                            ->preserveFilenames()
                            ->maxSize(25600)
                            ->helperText('Upload meeting docs, quotes, specs, invoices, photos.')
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project_number')
                    ->searchable()
                    ->sortable()
                    ->label('Project #'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->label('Project Name'),
                Tables\Columns\TextColumn::make('station.name')
                    ->label('Station')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('budget_amount')
                    ->money('USD')
                    ->sortable()
                    ->label('Budget'),
                Tables\Columns\TextColumn::make('spend_amount')
                    ->money('USD')
                    ->sortable()
                    ->label('Spent'),
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
                    ->sortable()
                    ->label('Target Date'),
                Tables\Columns\TextColumn::make('percent_complete')
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
                    ->relationship('station', 'station_number')
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
            ])
            ->defaultSort('created_at', 'desc');
    }
    
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Core Project Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('project_number')
                            ->label('Project Number'),
                        Infolists\Components\TextEntry::make('name')
                            ->label('Project Name'),
                        Infolists\Components\TextEntry::make('description')
                            ->columnSpanFull()
                            ->label('Description'),
                        Infolists\Components\TextEntry::make('project_manager')
                            ->label('Project Manager'),
                    ])
                    ->columns(2),
                    
                Infolists\Components\Section::make('Financial Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('budget_amount')
                            ->money('USD')
                            ->label('Budget Amount'),
                        Infolists\Components\TextEntry::make('spend_amount')
                            ->money('USD')
                            ->label('Spend Amount'),
                    ])
                    ->columns(2),
                    
                Infolists\Components\Section::make('Timeline')
                    ->schema([
                        Infolists\Components\TextEntry::make('start_date')
                            ->date('M d, Y')
                            ->label('Start Date'),
                        Infolists\Components\TextEntry::make('target_completion_date')
                            ->date('M d, Y')
                            ->label('Target Completion Date'),
                        Infolists\Components\TextEntry::make('actual_completion_date')
                            ->date('M d, Y')
                            ->label('Actual Completion Date'),
                    ])
                    ->columns(3),
                    
                Infolists\Components\Section::make('Status & Priority')
                    ->schema([
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn ($state): string => match ($state?->value ?? $state) {
                                'pending' => 'warning',
                                'in_progress' => 'info',
                                'completed' => 'success',
                                'on_hold' => 'danger',
                                default => 'gray',
                            })
                            ->label('Status'),
                        Infolists\Components\TextEntry::make('priority')
                            ->badge()
                            ->color(fn ($state): string => match ($state?->value ?? $state) {
                                'low' => 'gray',
                                'medium' => 'info',
                                'high' => 'warning',
                                'critical' => 'danger',
                                default => 'gray',
                            })
                            ->label('Priority'),
                    ])
                    ->columns(2),
                    
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
                    ])
                    ->columns(1),
                    
                Infolists\Components\Section::make('Notes')
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->html()
                            ->columnSpanFull()
                            ->label('Public Notes'),
                        Infolists\Components\TextEntry::make('internal_notes')
                            ->columnSpanFull()
                            ->label('Internal Notes')
                            ->visible(fn () => auth()->user()?->isAdmin ?? false),
                    ])
                    ->columns(1),
                    
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
                        Infolists\Components\TextEntry::make('updates_count')
                            ->label('Total Updates')
                            ->state(fn ($record) => $record->updates()->count()),
                    ])
                    ->columns(1),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\UpdatesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUnder25kProjects::route('/'),
            'create' => Pages\CreateUnder25kProject::route('/create'),
            'view' => Pages\ViewUnder25kProject::route('/{record}'),
            'edit' => Pages\EditUnder25kProject::route('/{record}/edit'),
        ];
    }
}
