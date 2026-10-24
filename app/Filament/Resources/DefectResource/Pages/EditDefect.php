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
            // No delete: a defect and its history are apparatus records. A report entered in
            // error is closed with "Close — No Action Required", which keeps the reason on file.
        ];
    }

    private function defect(): ApparatusDefect
    {
        $record = $this->getRecord();
        assert($record instanceof ApparatusDefect);

        return $record;
    }
}
