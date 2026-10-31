<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Admin\Pages\EmailCenter;
use App\Models\InboundEmail;
use App\Models\Mail\MailDraft;
use App\Models\Mail\MailThreadMessage;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailThreadIndexer;
use Filament\Pages\Page;
use Throwable;

/**
 * The old "Compose Email" address, kept so bookmarks, the Reply and Forward buttons on the old message views, and
 * any saved link keep working. It no longer composes anything: writing happens in the Email Center
 * (/admin/email), which saves a draft, sends through the same dispatcher and shows the outcome. This page only
 * sends the visitor there.
 *
 *   /admin/compose-email                              -> /admin/email
 *   /admin/compose-email?source=inbound&message=12&mode=reply
 *                                                     -> the same reply, open in the Email Center
 *
 * Access is the Email Center's send level (which still honours the older admin.communications.send), so nobody
 * gains or loses the ability to write mail through this address.
 */
final class ComposeEmail extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'Compose Email';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.compose-email';

    /** Email now has one entry in the sidebar; this address keeps working without one. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(MailAccess::class)->canSend($user);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->redirect($this->destination(), navigate: false);
    }

    private function destination(): string
    {
        $source = (string) request()->query('source', '');
        $mode = (string) request()->query('mode', 'reply');
        $id = filter_var(request()->query('message'), FILTER_VALIDATE_INT);
        if (! in_array($source, ['inbound', 'outbound'], true) || ! is_int($id) || $id < 1 || ! in_array($mode, [MailDraft::MODE_REPLY, MailDraft::MODE_REPLY_ALL, MailDraft::MODE_FORWARD], true)) {
            return EmailCenter::getUrl();
        }

        $thread = $this->threadOf($source, $id);

        return $thread === null
            ? EmailCenter::getUrl()
            : EmailCenter::getUrl(['thread' => $thread, 'compose' => $mode, 'msg' => "{$source}:{$id}"]);
    }

    /** The conversation holding that message, indexing it first when the indexer has not seen it yet. */
    private function threadOf(string $source, int $id): ?int
    {
        $column = $source === 'inbound' ? 'inbound_email_id' : 'outbound_email_id';
        $found = MailThreadMessage::query()->where($column, $id)->value('thread_id');
        if (is_numeric($found)) {
            return (int) $found;
        }
        try {
            $message = ($source === 'inbound' ? InboundEmail::query() : OutboundEmail::query())->find($id);
            if ($message === null) {
                return null;
            }
            $indexer = app(MailThreadIndexer::class);
            $source === 'inbound' ? $indexer->indexInbound($message) : $indexer->indexOutbound($message);
            $found = MailThreadMessage::query()->where($column, $id)->value('thread_id');
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return is_numeric($found) ? (int) $found : null;
    }
}
