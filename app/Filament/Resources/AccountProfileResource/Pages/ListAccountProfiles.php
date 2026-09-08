<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountProfileResource\Pages;

use App\Filament\Resources\AccountProfileResource;
use App\Filament\Resources\EmployeeResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListAccountProfiles extends ListRecords
{
    protected static string $resource = AccountProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('employees')->label('Employees & Access')->url(EmployeeResource::getUrl())];
    }
}
