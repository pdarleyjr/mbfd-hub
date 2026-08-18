<?php

declare(strict_types=1);

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelAssignmentResource\Pages;

use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelAssignmentResource;
use App\Services\PersonnelRequests\PersonnelRequestFulfillmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;

class EditPersonnelAssignment extends EditRecord
{
    protected static string $resource = PersonnelAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('return')->label('Return / Retire')->icon('heroicon-o-archive-box-x-mark')->color('warning')->visible(fn () => $this->record->status === 'active')->requiresConfirmation()->form([
                Select::make('status')->label('Disposition')->options(['returned' => 'Returned', 'retired' => 'Retired'])->default('returned')->required(),
                DatePicker::make('returned_at')->default(today())->required(),
                Textarea::make('reason')->label('Return or retirement reason')->required()->maxLength(2000),
            ])->action(fn (array $data) => app(PersonnelRequestFulfillmentService::class)->retire($this->record, auth()->user(), $data['returned_at'], $data['reason'], $data['status'])),
        ];
    }
}
