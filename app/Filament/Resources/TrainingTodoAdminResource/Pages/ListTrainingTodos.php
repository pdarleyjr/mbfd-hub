<?php

declare(strict_types=1);

namespace App\Filament\Resources\TrainingTodoAdminResource\Pages;

use App\Filament\Resources\TrainingTodoAdminResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListTrainingTodos extends ListRecords
{
    protected static string $resource = TrainingTodoAdminResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->visible(fn (): bool => TrainingTodoAdminResource::canCreate())];
    }
}
