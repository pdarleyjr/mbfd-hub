<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\SurveyResource;
use Filament\Resources\Pages\EditRecord;

class EditSurvey extends EditRecord
{
    protected static string $resource = SurveyResource::class;
}
