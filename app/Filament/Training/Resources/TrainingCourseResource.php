<?php

declare(strict_types=1);

namespace App\Filament\Training\Resources;

use App\Filament\Training\Resources\TrainingCourseResource\Pages;
use App\Models\Training\TrainingCourse;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TrainingCourseResource extends Resource
{
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
            CheckboxList::make('classifications')->options(array_combine(TrainingCourse::CLASSIFICATIONS, TrainingCourse::CLASSIFICATIONS))
                ->required()->columns(2)->columnSpanFull(),
            Textarea::make('description')->maxLength(5000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->label('Course')->searchable()->sortable()->weight('semibold'),
            Tables\Columns\TextColumn::make('classifications')->badge()->separator(', '),
            Tables\Columns\TextColumn::make('default_location')->label('Default location'),
        ])->actions([
            Tables\Actions\Action::make('sessions')->label('Rosters')->icon('heroicon-o-clipboard-document-list')
                ->url(fn (TrainingCourse $record): string => TrainingSessionResource::getUrl('index', ['tableFilters' => ['training_course_id' => ['value' => $record->id]]])),
            Tables\Actions\EditAction::make(),
        ])->defaultSort('name')->emptyStateHeading('Create your first training course')
            ->emptyStateDescription('Reuse a course for every dated class. Each class keeps its own attendance and certified records.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrainingCourses::route('/'),
            'create' => Pages\CreateTrainingCourse::route('/create'),
            'edit' => Pages\EditTrainingCourse::route('/{record}/edit'),
        ];
    }
}
