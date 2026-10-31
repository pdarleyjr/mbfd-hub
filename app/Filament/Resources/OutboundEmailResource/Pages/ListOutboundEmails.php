<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutboundEmailResource\Pages;

use App\Filament\Admin\Pages\EmailCenter;
use App\Filament\Resources\OutboundEmailResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The old Outbound Email list. A plain visit to its address opens the Email Center's Sent folder. With ?records=1,
 * which the Email Center's health page links to, it stays the searchable log with its Excel and PDF exports.
 */
final class ListOutboundEmails extends ListRecords
{
    protected static string $resource = OutboundEmailResource::class;

    public function mount(): void
    {
        if (request()->is('admin/outbound-emails') && ! request()->boolean('records')) {
            $this->authorizeAccess(); // the page's own rule: whoever could open this list can still open it
            $this->redirect(EmailCenter::getUrl(['folder' => 'sent']), navigate: false);

            return;
        }
        parent::mount();
    }
}
