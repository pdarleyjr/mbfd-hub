<?php

declare(strict_types=1);

namespace App\Filament\Resources\InboundEmailResource\Pages;

use App\Filament\Pages\ComposeEmail;
use App\Filament\Resources\InboundEmailResource;
use App\Models\InboundEmail;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

final class ViewInboundEmail extends ViewRecord
{
    protected static string $resource = InboundEmailResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reply')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => ComposeEmail::canAccess())
                ->url(fn (): string => ComposeEmail::getUrl([
                    'to' => $this->inboundEmail()->from_address,
                    'subject' => str_starts_with((string) $this->inboundEmail()->subject, 'Re: ')
                        ? $this->inboundEmail()->subject
                        : 'Re: '.$this->inboundEmail()->subject,
                ])),
        ];
    }

    private function inboundEmail(): InboundEmail
    {
        $record = $this->getRecord();

        if (! $record instanceof InboundEmail) {
            throw new LogicException('The inbound email record is unavailable.');
        }

        return $record;
    }
}
