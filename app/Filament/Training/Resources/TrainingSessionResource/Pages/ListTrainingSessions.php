<?php

declare(strict_types=1);

namespace App\Filament\Training\Resources\TrainingSessionResource\Pages;

use App\Filament\Training\Resources\TrainingSessionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingSessions extends ListRecords
{
    protected static string $resource = TrainingSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New session')];
    }
}
