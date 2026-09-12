<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workgroup;

use App\Filament\Resources\Workgroup\Concerns\ResolvesWorkgroupAccess;
use App\Models\User;
use App\Models\WorkgroupSurvey;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SurveyResource extends Resource
{
    use ResolvesWorkgroupAccess;

    protected static ?string $model = WorkgroupSurvey::class;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Evaluations / Surveys';
    protected static ?string $navigationLabel = 'Manage surveys';

    public static function form(Form $form): Form
    {
        $locked = fn (?WorkgroupSurvey $record): bool => $record?->hasResponses() ?? false;

        return $form->schema([
            Forms\Components\Section::make('Survey details')->schema([
                Forms\Components\TextInput::make('title')->required()->maxLength(255)->disabled($locked),
                Forms\Components\Textarea::make('description')->columnSpanFull()->disabled($locked),
                Forms\Components\Select::make('status')->options(['draft' => 'Draft', 'active' => 'Open', 'closed' => 'Closed'])->required(),
                Forms\Components\Toggle::make('is_anonymous')->label('De-identified response reporting')->disabled($locked),
                Forms\Components\TextInput::make('minimum_subgroup_size')->numeric()->minValue(3)->default(3)->required()->disabled($locked),
            ])->columns(2),
            Forms\Components\Section::make('Questions')->description('Question definitions lock once responses exist; duplicate a survey to create a new revision.')->schema([
                Forms\Components\Repeater::make('questions')->relationship()->orderColumn('position')->schema([
                    Forms\Components\TextInput::make('position')->numeric()->required(),
                    Forms\Components\Select::make('type')->options(['single' => 'Single choice / rating', 'multi' => 'Multiple choice', 'matrix' => 'Matrix/rating grid', 'compound' => 'Compound block'])->required(),
                    Forms\Components\Textarea::make('prompt')->required()->columnSpanFull(),
                    Forms\Components\Textarea::make('help_text')->columnSpanFull(),
                    Forms\Components\Toggle::make('is_required')->default(true),
                    Forms\Components\Textarea::make('configuration')
                        ->required()
                        ->rows(8)
                        ->columnSpanFull()
                        ->formatStateUsing(fn ($state): string => json_encode($state ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                        ->dehydrateStateUsing(function (string $state): array {
                            $configuration = json_decode($state, true, 512, JSON_THROW_ON_ERROR);
                            abort_unless(is_array($configuration), 422, 'Question configuration must be a JSON object.');

                            return $configuration;
                        })
                        ->helperText('JSON configuration: options, rows/parts, scoring, favorable options, limits, and exclusive options.'),
                ])->disabled($locked)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('title')->searchable()->wrap(),
            Tables\Columns\TextColumn::make('revision')->label('Revision'),
            Tables\Columns\TextColumn::make('status')->badge(),
            Tables\Columns\IconColumn::make('is_anonymous')->boolean()->label('De-identified'),
            Tables\Columns\TextColumn::make('responses_count')->counts('responses')->label('Responses'),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\Action::make('duplicate')
                ->label('Duplicate as new revision')
                ->requiresConfirmation()
                ->action(function (WorkgroupSurvey $record): void {
                    DB::transaction(function () use ($record): void {
                        $copy = $record->replicate(['created_at', 'updated_at']);
                        $copy->forceFill([
                            'title' => $record->title.' (copy)',
                            'parent_survey_id' => $record->id,
                            'status' => 'draft',
                            'revision' => $record->revision + 1,
                            'created_by' => auth()->id(),
                        ])->save();
                        foreach ($record->questions as $question) {
                            $copy->questions()->create($question->only(['position', 'type', 'prompt', 'help_text', 'is_required', 'configuration']));
                        }
                    });
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSurveys::route('/'), 'create' => Pages\CreateSurvey::route('/create'), 'edit' => Pages\EditSurvey::route('/{record}/edit')];
    }

    public static function canViewAny(): bool { return self::currentUserCanManageAny(); }
    public static function canCreate(): bool { return self::currentUserCanManageAny(); }
    public static function canEdit($record): bool { $user = self::currentWorkgroupUser(); return $user instanceof User && $record instanceof WorkgroupSurvey && self::workgroupAccess()->canManageSurvey($user, $record); }
    public static function getEloquentQuery(): Builder { return self::workgroupAccess()->scopeManageSurveys(parent::getEloquentQuery(), self::currentWorkgroupUser()); }

    private static function currentUserCanManageAny(): bool
    {
        $user = self::currentWorkgroupUser();
        return $user instanceof User && self::workgroupAccess()->canManageAnyWorkgroup($user);
    }
}
