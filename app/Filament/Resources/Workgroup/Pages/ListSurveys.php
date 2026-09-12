<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\SurveyResource;
use Filament\Resources\Pages\ListRecords;

class ListSurveys extends ListRecords
{
    protected static string $resource = SurveyResource::class;
}

