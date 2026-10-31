<?php

declare(strict_types=1);

namespace App\Filament\Resources\InboundEmailResource\Pages;

use App\Filament\Admin\Pages\EmailCenter;
use App\Filament\Resources\InboundEmailResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The old Inbound Email list. A plain visit to its address opens the Email Center's Inbox. With ?records=1, which
 * the Email Center's health page links to, it stays the searchable log with its Excel and PDF exports.
 */
final class ListInboundEmails extends ListRecords
{
    protected static string $resource = InboundEmailResource::class;

    public function mount(): void
    {
        if (request()->is('admin/inbound-emails') && ! request()->boolean('records')) {
            $this->authorizeAccess(); // the page's own rule: whoever could open this list can still open it
            $this->redirect(EmailCenter::getUrl(), navigate: false);

            return;
        }
        parent::mount();
    }

    public function getSubheading(): string
    {
        return 'Incoming messages must be under 3 MB in total. Keep attachments under 2 MB combined; long messages reduce the available space.';
    }
}
