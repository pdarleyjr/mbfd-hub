<?php

declare(strict_types=1);

namespace App\Filament\Resources\RosterImportResource\Pages;

use App\Filament\Resources\RosterImportResource;
use App\Services\DailyRoster\RosterFreshness;
use App\Services\DailyRoster\RosterFreshnessResolver;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;

final class ListRosterImports extends ListRecords
{
    protected static string $resource = RosterImportResource::class;

    public function getSubheading(): string
    {
        $status = app(RosterFreshnessResolver::class)->status();
        $state = match ($status->state) {
            RosterFreshness::CURRENT => 'Today\'s roster is published.',
            RosterFreshness::AWAITING => 'Today\'s roster has not arrived yet.',
            RosterFreshness::DELAYED => 'Today\'s roster is late.',
            RosterFreshness::NEEDS_REVIEW => 'An export for today needs review.',
            default => 'No roster has been published yet.',
        };
        $asOf = $status->asOf?->setTimezone('America/New_York')->format('M j, g:i A');

        return $asOf === null ? $state : "{$state} Last update {$asOf}.";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reconcile')
                ->label('Check for stuck imports')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(static fn (): bool => RosterImportResource::canManage())
                ->action(static function (): void {
                    Artisan::call('daily-roster:reconcile');
                    Notification::make()->success()->title('Stuck imports checked')->send();
                }),
        ];
    }
}
