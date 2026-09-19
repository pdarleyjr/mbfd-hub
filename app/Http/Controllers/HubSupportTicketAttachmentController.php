<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HubSupportTicketAttachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HubSupportTicketAttachmentController extends Controller
{
    public function member(Request $request, HubSupportTicketAttachment $attachment): StreamedResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->isAuthenticationAllowed()
            && $attachment->ticket()->where('reported_by_user_id', $user->id)->exists(), 403);

        return $this->download($attachment);
    }

    public function admin(Request $request, HubSupportTicketAttachment $attachment): StreamedResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->isAuthenticationAllowed()
            && $user->hasCurrentAdminPanelEntitlement()
            && $user->can('view', $attachment->ticket), 403);

        return $this->download($attachment);
    }

    private function download(HubSupportTicketAttachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->storage_path), 404);

        return response()->streamDownload(
            fn () => print Storage::disk($attachment->disk)->get($attachment->storage_path),
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
    }
}
