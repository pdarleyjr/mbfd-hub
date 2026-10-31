<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutboundEmailResource\Pages;

use App\Filament\Concerns\RedirectsToMailThread;
use App\Filament\Pages\ComposeEmail;
use App\Filament\Resources\OutboundEmailResource;
use App\Services\Communications\EmailConversation;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

final class ViewOutboundEmail extends ViewRecord
{
    use RedirectsToMailThread;

    protected static string $resource = OutboundEmailResource::class;

    protected static string $view = 'filament.communications.view-email';

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->redirectToIndexedThread('outbound', $this->getRecord()->getKey());
    }

    public function getRecord(): \App\Models\OutboundEmail
    {
        $record = parent::getRecord();
        if (! $record instanceof \App\Models\OutboundEmail) {
            throw new \LogicException('The email record is unavailable.');
        }

        return $record;
    }

    public function downloadAttachment(int $index): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(OutboundEmailResource::canViewAny(), 403);
        $service = app(\App\Services\Communications\EmailConversation::class);
        abort_unless($service->canRespond($this->getRecord()), 403);
        $file = $service->attachments($this->getRecord(), [$index])[0];

        return response()->streamDownload(fn () => print (base64_decode($file['content'])), $file['filename'], ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    protected function getHeaderActions(): array
    {
        $actions = [Action::make('back')->label('Back to Outbound Emails')->icon('heroicon-o-arrow-left')->color('gray')->url(fn (): string => class_exists(\App\Support\HubNavigation::class) ? \App\Support\HubNavigation::backUrl(OutboundEmailResource::getUrl('index')) : OutboundEmailResource::getUrl('index'))];
        foreach (['reply' => 'Reply', 'reply_all' => 'Reply all', 'forward' => 'Forward'] as $mode => $label) {
            $actions[] = Action::make($mode)->label($label)->color($mode === 'reply' ? 'primary' : 'gray')
                ->icon($mode === 'forward' ? 'heroicon-o-arrow-uturn-right' : 'heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => ComposeEmail::canAccess() && app(EmailConversation::class)->canRespond($this->getRecord()))
                ->url(fn (): string => ComposeEmail::getUrl(['source' => 'outbound', 'message' => $this->getRecord()->getKey(), 'mode' => $mode]));
        }

        return $actions;
    }
}
