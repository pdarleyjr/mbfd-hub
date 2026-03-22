<?php

namespace App\Filament\Resources\RecommendationResource\Pages;

use App\Filament\Resources\RecommendationResource;
use Filament\Resources\Pages\ListRecords;

class ListRecommendations extends ListRecords
{
    protected static string $resource = RecommendationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create action - recommendations are auto-generated
        ];
    }
}
