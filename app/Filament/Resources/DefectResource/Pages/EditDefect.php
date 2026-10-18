<?php

namespace App\Filament\Resources\DefectResource\Pages;

use App\Filament\Resources\DefectResource;
use App\Filament\Support\DefectServiceTicketActions;
use App\Models\ApparatusDefect;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDefect extends EditRecord
{
    protected static string $resource = DefectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DefectServiceTicketActions::configureView(
                Actions\Action::make(DefectServiceTicketActions::VIEW),
                fn (): ApparatusDefect => $this->defect(),
            ),
            DefectServiceTicketActions::configureCreate(
                Actions\Action::make(DefectServiceTicketActions::CREATE),
                fn (): ApparatusDefect => $this->defect(),
            )->after(fn () => $this->defect()->refresh()),
            Actions\DeleteAction::make(),
        ];
    }

    private function defect(): ApparatusDefect
    {
        $record = $this->getRecord();
        assert($record instanceof ApparatusDefect);

        return $record;
    }
}
