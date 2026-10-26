<?php

declare(strict_types=1);

namespace App\Filament\Training\Resources;

use App\Filament\Concerns\HasExportActions;
use App\Filament\Training\Resources\TrainingCourseResource\Pages;
use App\Models\Training\TrainingCourse;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TrainingCourseResource extends Resource
{
    use HasExportActions;

    protected static ?string $model = TrainingCourse::class;

    protected static ?string $navigationGroup = 'Training';

    protected static ?string $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationLabel = 'Courses';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'training-courses';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->label('Course name')->required()->maxLength(200),
            TextInput::make('default_location')->label('Default location')->maxLength(255),
            TagsInput::make('classifications')->suggestions(fn (): array => TrainingCourse::classificationSuggestions())
                ->mutateStateForValidationUsing(fn ($state) => is_array($state) ? TrainingCourse::normalizeClassifications($state) : $state)
                ->required()->rules(['array', 'min:1', 'max:16'])
                ->nestedRecursiveRules(['bail', 'required', 'string', 'max:80', 'regex:/\S/u', 'distinct:ignore_case'])
                ->helperText('Choose a suggestion or type a new classification and press Enter. Saved classifications are reusable and shown in the public class list. Up to 16 classifications, 80 characters each.')
                ->columnSpanFull(),
            Textarea::make('description')->maxLength(5000)->columnSpanFull(),
            Toggle::make('track_attendance_matrix')->label('Enable attendance tracking')->default(false)
                ->helperText('Compare documented module attendance across the department. Turning this off keeps every roster and module.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions($table->columns([
            Tables\Columns\TextColumn::make('name')->label('Course')->searchable()->sortable()->weight('semibold'),
            Tables\Columns\TextColumn::make('classifications')->badge()->separator(', '),
            Tables\Columns\TextColumn::make('default_location')->label('Default location'),
        ])->actions([
            Tables\Actions\Action::make('modules')->label('Modules')
                ->url(fn (TrainingCourse $record): string => static::getUrl('modules', ['record' => $record])),
            Tables\Actions\Action::make('matrix')->label('Attendance matrix')->icon('heroicon-o-table-cells')
                ->visible(fn (TrainingCourse $record): bool => $record->track_attendance_matrix)
                ->url(fn (TrainingCourse $record): string => static::getUrl('matrix', ['record' => $record])),
            Tables\Actions\Action::make('paper')->label('Paper rosters')
                ->url(fn (TrainingCourse $record): string => static::getUrl('paper', ['record' => $record])),
            Tables\Actions\Action::make('sessions')->label('Rosters')->icon('heroicon-o-clipboard-document-list')
                ->url(fn (TrainingCourse $record): string => TrainingSessionResource::getUrl('index', ['tableFilters' => ['training_course_id' => ['value' => $record->id]]])),
            Tables\Actions\EditAction::make(),
        ])->defaultSort('name')->emptyStateHeading('Create your first training course')
            ->emptyStateDescription('Reuse a course for every dated class. Each class keeps its own attendance and certified records.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrainingCourses::route('/'),
            'create' => Pages\CreateTrainingCourse::route('/create'),
            'edit' => Pages\EditTrainingCourse::route('/{record}/edit'),
            'modules' => Pages\ManageTrainingModules::route('/{record}/modules'),
            'matrix' => Pages\ViewTrainingMatrix::route('/{record}/matrix'),
            'paper' => Pages\ReviewTrainingPaper::route('/{record}/paper'),
        ];
    }
}
