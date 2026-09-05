<?php

declare(strict_types=1);

namespace App\Filament\Resources\TrainingTodoAdminResource\Pages;

use App\Filament\Resources\TrainingTodoAdminResource;
use Filament\Resources\Pages\EditRecord;

final class EditTrainingTodo extends EditRecord
{
    protected static string $resource = TrainingTodoAdminResource::class;
}
