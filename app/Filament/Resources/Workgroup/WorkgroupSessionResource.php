<?php

namespace App\Filament\Resources\Workgroup;

use App\Filament\Resources\Workgroup\Pages;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class WorkgroupSessionResource extends Resource
{
    protected static ?string $model = WorkgroupSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationGroup = 'Workgroup Management';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Session Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Session Name'),
                        Forms\Components\Select::make('workgroup_id')
                            ->label('Workgroup')
                            ->options(fn () => Workgroup::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Forms\Components\DatePicker::make('start_date')
                            ->label('Start Date'),
                        Forms\Components\DatePicker::make('end_date')
                            ->label('End Date'),
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'draft' => 'Draft',
                                'active' => 'Active',
                                'completed' => 'Completed',
                            ])
                            ->default('draft')
                            ->required(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Official Evaluators')
                    ->description('Select which members are official evaluators. Only their submissions count toward analytics and product rankings.')
                    ->schema([
                        Forms\Components\Select::make('official_evaluators')
                            ->label('Official Evaluators')
                            ->multiple()
                            ->searchable()
                            ->options(function (Forms\Get $get) {
                                $workgroupId = $get('workgroup_id');
                                if (!$workgroupId) return [];
                                return WorkgroupMember::where('workgroup_id', $workgroupId)
                                    ->where('is_active', true)
                                    ->with('user')
                                    ->get()
                                    ->pluck('user.name', 'user.id')
                                    ->toArray();
                            })
                            ->default(function ($record) {
                                if (!$record) return [];
                                return $record->officialEvaluators()->pluck('users.id')->toArray();
                            })
                            ->helperText('Only selected evaluators\' submissions will count in score calculations and reports.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Session Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')->label('Name'),
                        Infolists\Components\TextEntry::make('workgroup.name')->label('Workgroup'),
                        Infolists\Components\TextEntry::make('start_date')->date(),
                        Infolists\Components\TextEntry::make('end_date')->date(),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'draft' => 'gray',
                                'active' => 'success',
                                'completed' => 'info',
                                default => 'gray',
                            }),
                    ])->columns(3),
                Infolists\Components\Section::make('Official Evaluators')
                    ->schema([
                        Infolists\Components\TextEntry::make('official_evaluators_list')
                            ->label('Official Evaluators')
                            ->state(fn ($record) => $record->officialEvaluators()->with('pivot')->get()->pluck('name')->join(', ') ?: 'None assigned'),
                    ]),
                Infolists\Components\Section::make('Statistics')
                    ->schema([
                        Infolists\Components\TextEntry::make('files_count')
                            ->label('Files')
                            ->state(fn ($record) => $record->files()->count()),
                        Infolists\Components\TextEntry::make('products_count')
                            ->label('Products')
                            ->state(fn ($record) => $record->candidateProducts()->count()),
                        Infolists\Components\TextEntry::make('submissions_count')
                            ->label('Submissions')
                            ->state(fn ($record) => \App\Models\EvaluationSubmission::whereHas('candidateProduct', function ($query) use ($record) {
                                $query->where('workgroup_session_id', $record->id);
                            })->count()),
                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->label('Name'),
                Tables\Columns\TextColumn::make('workgroup.name')->searchable()->sortable()->label('Workgroup'),
                Tables\Columns\TextColumn::make('start_date')->date()->sortable()->label('Start Date'),
                Tables\Columns\TextColumn::make('end_date')->date()->sortable()->label('End Date'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'active' => 'success',
                        'completed' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('official_evaluators_count')
                    ->label('Official Evaluators')
                    ->state(fn ($record) => $record->officialEvaluators()->count())
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('products_count')
                    ->counts('candidateProducts')
                    ->label('Products'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('workgroup_id')
                    ->label('Workgroup')
                    ->options(fn () => Workgroup::pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(['draft' => 'Draft', 'active' => 'Active', 'completed' => 'Completed']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('manageEvaluators')
                    ->label('Manage Evaluators')
                    ->icon('heroicon-o-user-plus')
                    ->color('info')
                    ->form([
                        Forms\Components\Select::make('official_evaluator_ids')
                            ->label('Official Evaluators')
                            ->multiple()
                            ->searchable()
                            ->options(function ($record) {
                                return WorkgroupMember::where('workgroup_id', $record->workgroup_id)
                                    ->where('is_active', true)
                                    ->with('user')
                                    ->get()
                                    ->pluck('user.name', 'user.id')
                                    ->toArray();
                            })
                            ->default(fn ($record) => $record->officialEvaluators()->pluck('users.id')->toArray())
                            ->helperText('Only selected evaluators count toward analytics.'),
                    ])
                    ->action(function (WorkgroupSession $record, array $data): void {
                        $userIds = $data['official_evaluator_ids'] ?? [];
                        // Sync: remove old, add new
                        $record->users()->detach();
                        foreach ($userIds as $userId) {
                            $record->users()->attach($userId, ['is_official_evaluator' => true]);
                        }
                        Notification::make()
                            ->title('Official evaluators updated (' . count($userIds) . ' assigned)')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * After creating/editing, sync official evaluators from the form.
     */
    public static function afterCreate($record, array $data): void
    {
        static::syncOfficialEvaluators($record, $data);
    }

    public static function afterSave($record, array $data): void
    {
        static::syncOfficialEvaluators($record, $data);
    }

    protected static function syncOfficialEvaluators($record, array $data): void
    {
        $evaluatorIds = $data['official_evaluators'] ?? [];
        if (!empty($evaluatorIds)) {
            $record->users()->detach();
            foreach ($evaluatorIds as $userId) {
                $record->users()->attach($userId, ['is_official_evaluator' => true]);
            }
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkgroupSessions::route('/'),
            'create' => Pages\CreateWorkgroupSession::route('/create'),
            'view' => Pages\ViewWorkgroupSession::route('/{record}'),
            'edit' => Pages\EditWorkgroupSession::route('/{record}/edit'),
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
