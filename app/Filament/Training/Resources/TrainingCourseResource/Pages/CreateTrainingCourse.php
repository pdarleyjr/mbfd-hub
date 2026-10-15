<?php

declare(strict_types=1);

namespace App\Filament\Training\Resources\TrainingCourseResource\Pages;

use App\Filament\Training\Resources\TrainingCourseResource;
use App\Services\Training\TrainingRosterService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTrainingCourse extends CreateRecord
{
    protected static string $resource = TrainingCourseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(TrainingRosterService::class)->createCourse($data, auth()->user());
    }
}
