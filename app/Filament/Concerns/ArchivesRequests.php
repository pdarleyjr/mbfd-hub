<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\ApparatusServiceTicket;
use App\Models\HubSupportTicket;
use App\Models\PersonnelRequest;
use App\Models\StationRequest;
use App\Models\User;
use App\Services\RequestArchivalService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

trait ArchivesRequests
{
    /** @return array<Action> */
    protected function getArchiveActions(): array
    {
        return [
            Action::make('archive')
                ->label('Archive')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->visible(fn (): bool => $this->canArchiveRequest() && ! $this->archiveRequestRecord()->isArchived())
                ->form([Textarea::make('archive_reason')->label('Archive reason (optional)')->maxLength(2000)])
                ->requiresConfirmation()
                ->action(fn (array $data) => $this->applyRequestArchive(true, $data['archive_reason'] ?? null)),
            Action::make('restore_archive')
                ->label('Restore')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => $this->canArchiveRequest() && $this->archiveRequestRecord()->isArchived())
                ->requiresConfirmation()
                ->action(fn () => $this->applyRequestArchive(false)),
        ];
    }

    private function canArchiveRequest(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(RequestArchivalService::class)->canManage($actor, $this->archiveRequestRecord());
    }

    private function applyRequestArchive(bool $archive, ?string $reason = null): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $service = app(RequestArchivalService::class);
        $this->record = $archive
            ? $service->archive($this->archiveRequestRecord(), $actor, $reason)
            : $service->restore($this->archiveRequestRecord(), $actor);
        Notification::make()->title($archive ? 'Request archived' : 'Request restored')->success()->send();
    }

    private function archiveRequestRecord(): PersonnelRequest|StationRequest|ApparatusServiceTicket|HubSupportTicket
    {
        $record = $this->getRecord();
        assert($record instanceof PersonnelRequest || $record instanceof StationRequest || $record instanceof ApparatusServiceTicket || $record instanceof HubSupportTicket);

        return $record;
    }
}
