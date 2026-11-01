<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusChangeoutResource\Pages;

use App\Filament\Concerns\RunsFleetChangeoutActions;
use App\Filament\Resources\ApparatusChangeoutResource;
use App\Models\ApparatusChangeout;
use App\Models\User;
use App\Services\Fleet\ApparatusChangeoutService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/** One changeout: the trucks, what the crew took and left, and the move in readable words. History, plus Acknowledge. */
class ViewApparatusChangeout extends ViewRecord
{
    use RunsFleetChangeoutActions;

    protected static string $resource = ApparatusChangeoutResource::class;

    public function getTitle(): string
    {
        return $this->changeout()->headline();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('acknowledgeChangeout')
                ->label('Acknowledge')
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->visible(fn (): bool => ! $this->changeout()->isAcknowledged() && (auth()->user()?->can('acknowledge', ApparatusChangeout::class) ?? false))
                ->action(fn () => $this->runFleetAction(
                    fn (User $actor) => app(ApparatusChangeoutService::class)->acknowledge((int) $this->changeout()->getKey(), $actor),
                    'Vehicle change acknowledged',
                )),
        ];
    }

    private function changeout(): ApparatusChangeout
    {
        $record = $this->getRecord();
        abort_unless($record instanceof ApparatusChangeout, 404);

        return $record;
    }
}
