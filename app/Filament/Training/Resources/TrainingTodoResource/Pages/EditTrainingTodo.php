<?php

namespace App\Filament\Training\Resources\TrainingTodoResource\Pages;

use App\Filament\Training\Resources\TrainingTodoResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTrainingTodo extends EditRecord
{
    protected static string $resource = TrainingTodoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
