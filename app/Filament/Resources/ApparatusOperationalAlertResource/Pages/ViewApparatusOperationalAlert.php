<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusOperationalAlertResource\Pages;

use App\Filament\Concerns\RunsFleetChangeoutActions;
use App\Filament\Resources\ApparatusOperationalAlertResource;
use App\Models\ApparatusOperationalAlert;
use App\Models\User;
use App\Services\Fleet\ApparatusOperationalAlertService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ViewRecord;

class ViewApparatusOperationalAlert extends ViewRecord
{
    use RunsFleetChangeoutActions;

    protected static string $resource = ApparatusOperationalAlertResource::class;

    public function getTitle(): string
    {
        return $this->alert()->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->color('gray')->visible(fn (): bool => $this->canChange()),
            Actions\Action::make('closeAlert')
                ->label('Close alert')
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->visible(fn (): bool => $this->canChange())
                ->modalHeading('Close this alert')
                ->modalDescription('It comes off every station page. The history stays. Nothing on the truck changes.')
                ->modalSubmitActionLabel('Close alert')
                ->form([self::noteField('Note (optional)')])
                ->action(fn (array $data) => $this->runFleetAction(
                    fn (User $actor) => app(ApparatusOperationalAlertService::class)->close($this->alert(), $actor, $data['note'] ?? null),
                    'Alert closed',
                )),
            Actions\Action::make('cancelAlert')
                ->label('Cancel alert')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->canChange())
                ->modalHeading('Cancel this alert')
                ->modalDescription('Use this when the plan no longer stands. It comes off every station page. The history stays. Nothing on the truck changes.')
                ->modalSubmitActionLabel('Cancel alert')
                ->modalCancelActionLabel('Keep alert')
                ->form([self::noteField('Why it was cancelled (optional)')])
                ->action(fn (array $data) => $this->runFleetAction(
                    fn (User $actor) => app(ApparatusOperationalAlertService::class)->cancel($this->alert(), $actor, $data['note'] ?? null),
                    'Alert cancelled',
                )),
        ];
    }

    private static function noteField(string $label): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make('note')->label($label)->rows(3)->maxLength(1000);
    }

    private function canChange(): bool
    {
        return $this->alert()->isActive() && (auth()->user()?->can('update', $this->alert()) ?? false);
    }

    private function alert(): ApparatusOperationalAlert
    {
        $record = $this->getRecord();
        abort_unless($record instanceof ApparatusOperationalAlert, 404);

        return $record;
    }
}
