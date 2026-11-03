<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPlaybookResource\Pages;

use App\Filament\Resources\AiPlaybookResource;
use Filament\Resources\Pages\ListRecords;

final class ListAiPlaybooks extends ListRecords
{
    protected static string $resource = AiPlaybookResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
