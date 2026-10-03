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
            Actions\DeleteAction::make()->label('Move to Trash')
                ->modalDescription('Attachments and history are retained. The task can be restored from Trash.'),
            Actions\RestoreAction::make(),
        ];
    }
}
