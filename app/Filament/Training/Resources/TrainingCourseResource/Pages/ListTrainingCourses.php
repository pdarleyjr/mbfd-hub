<?php

declare(strict_types=1);

namespace App\Filament\Training\Resources\TrainingCourseResource\Pages;

use App\Filament\Training\Resources\TrainingCourseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingCourses extends ListRecords
{
    protected static string $resource = TrainingCourseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New course')];
    }
}
