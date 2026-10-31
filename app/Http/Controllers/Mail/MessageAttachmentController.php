<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\InboundEmail;
use App\Models\OutboundEmail;
use App\Services\Communications\EmailConversation;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailAttachmentLocator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one attachment of one message, always as a download. The claimed MIME type is never served: the
 * bytes go out as application/octet-stream with nosniff, so a file that says it is an image but holds HTML
 * can never be rendered by the browser. The index must exist in that message's own metadata and the path is
 * re-proved by MailAttachmentLocator (private disk, that message's directory, no traversal).
 */
final class MessageAttachmentController extends Controller
{
    public function __invoke(Request $request, MailAccess $access, MailAttachmentLocator $locator, EmailConversation $conversation, string $kind, int $id, int $index): StreamedResponse
    {
        abort_unless($access->canView($request->user()), 403);
        $email = ($kind === 'inbound' ? InboundEmail::query() : OutboundEmail::query())->findOrFail($id);
        // Outbound sources whose content has not been reviewed keep their files private, as the older pages did.
        abort_unless($conversation->canRespond($email), 403);
        $located = $locator->locate($email, $index);
        abort_if($located === null, 404);

        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\"]+/u', '_', $located['filename'])) ?: 'attachment';

        return Storage::disk($located['disk'])->download($located['path'], $name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
