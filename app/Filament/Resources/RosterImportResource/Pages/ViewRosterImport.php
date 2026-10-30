<?php

declare(strict_types=1);

namespace App\Filament\Resources\RosterImportResource\Pages;

use App\Filament\Resources\RosterImportResource;
use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\RosterImportActions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Pages\ViewRecord;

final class ViewRosterImport extends ViewRecord
{
    protected static string $resource = RosterImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->label('Retry')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => RosterImportResource::canManage() && app(RosterImportActions::class)->canRetry($this->importRecord()))
                ->action(fn () => app(RosterImportActions::class)->retry($this->importRecord(), auth()->user())),
            Action::make('retryAttested')
                ->label('Confirm genuine and retry')
                ->icon('heroicon-o-shield-check')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Confirm this email is the genuine TeleStaff export?')
                ->modalDescription(RosterImportResource::ATTEST_DESCRIPTION)
                ->form([Forms\Components\Textarea::make('reason')->label('How you confirmed it')->required()->maxLength(500)])
                ->visible(fn (): bool => RosterImportResource::canManage() && app(RosterImportActions::class)->canRetryAttested($this->importRecord()))
                ->action(fn (array $data) => app(RosterImportActions::class)->retryAttested($this->importRecord(), auth()->user(), (string) $data['reason'])),
            Action::make('retryCleanup')
                ->label('Retry email cleanup')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(RosterImportResource::RETRY_CLEANUP_DESCRIPTION)
                ->visible(fn (): bool => RosterImportResource::canManage() && app(RosterImportActions::class)->canRetryCleanup($this->importRecord()))
                ->action(fn () => app(RosterImportActions::class)->retryCleanup($this->importRecord(), auth()->user())),
            Action::make('activate')
                ->label('Show this version')
                ->icon('heroicon-o-backward')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Show this roster version to members?')
                ->modalDescription('The duty date stays on this version and newer TeleStaff exports for it are held until you release it. Nothing is deleted.')
                ->form([Forms\Components\Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                ->visible(fn (): bool => RosterImportResource::canManage() && app(RosterImportActions::class)->canActivate($this->importRecord()))
                ->action(fn (array $data) => app(RosterImportActions::class)->activate($this->importRecord(), auth()->user(), (string) $data['reason'])),
            Action::make('release')
                ->label('Release pinned date')
                ->icon('heroicon-o-lock-open')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('The next TeleStaff export for this duty date can publish again.')
                ->form([Forms\Components\Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                ->visible(fn (): bool => RosterImportResource::canManage() && app(RosterImportActions::class)->canRelease($this->importRecord()))
                ->action(fn (array $data) => app(RosterImportActions::class)->release($this->importRecord(), auth()->user(), (string) $data['reason'])),
        ];
    }

    private function importRecord(): RosterImport
    {
        $record = $this->getRecord();
        if (! $record instanceof RosterImport) {
            throw new \LogicException('The roster import is unavailable.');
        }

        return $record;
    }
}
