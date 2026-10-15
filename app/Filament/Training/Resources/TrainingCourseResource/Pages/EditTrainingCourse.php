<?php

declare(strict_types=1);

namespace App\Filament\Training\Resources\TrainingCourseResource\Pages;

use App\Filament\Training\Resources\TrainingCourseResource;
use App\Services\Training\TrainingRosterService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTrainingCourse extends EditRecord
{
    protected static string $resource = TrainingCourseResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(TrainingRosterService::class)->updateCourse($record, $data, auth()->user());
    }
}
