<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\CandidateProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCandidateProduct extends CreateRecord
{
    protected static string $resource = CandidateProductResource::class;
}
