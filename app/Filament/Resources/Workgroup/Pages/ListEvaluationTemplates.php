<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\EvaluationTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEvaluationTemplates extends ListRecords
{
    protected static string $resource = EvaluationTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
