<?php

declare(strict_types=1);

namespace App\Filament\Resources\TrainingTodoAdminResource\Pages;

use App\Filament\Resources\TrainingTodoAdminResource;

final class EditTrainingTodo extends \App\Filament\Training\Resources\TrainingTodoResource\Pages\EditTrainingTodo
{
    protected static string $resource = TrainingTodoAdminResource::class;
}
