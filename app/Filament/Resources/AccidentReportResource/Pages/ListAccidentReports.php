<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccidentReportResource\Pages;

use App\Filament\Resources\AccidentReportResource;
use Filament\Resources\Pages\ListRecords;

class ListAccidentReports extends ListRecords
{
    protected static string $resource = AccidentReportResource::class;

    public function getSubheading(): ?string
    {
        return 'Submitted vehicle accident reports and supervisor\'s reports of accident. An accident stays open until it is resolved.';
    }
}
