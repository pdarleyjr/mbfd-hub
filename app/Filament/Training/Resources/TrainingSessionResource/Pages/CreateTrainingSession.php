<?php

declare(strict_types=1);

namespace App\Filament\Training\Resources\TrainingSessionResource\Pages;

use App\Filament\Training\Resources\TrainingSessionResource;
use App\Models\Training\TrainingCourse;
use App\Services\Training\TrainingRosterService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTrainingSession extends CreateRecord
{
    protected static string $resource = TrainingSessionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(TrainingRosterService::class)->createSession(TrainingCourse::findOrFail($data['training_course_id']), $data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
