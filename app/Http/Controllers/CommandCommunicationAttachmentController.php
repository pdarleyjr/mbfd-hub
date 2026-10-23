<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CommandCommunicationAttachment;
use App\Models\User;
use App\Services\CommandCommunications\CommandCommunicationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a shared-conversation attachment from the private disk. The route only requires a
 * signed-in user; access is decided per attachment from the post's audience (a channel the
 * user can read), and the attachments of a removed post are never served.
 */
final class CommandCommunicationAttachmentController extends Controller
{
    public function show(Request $request, string $publicId, CommandCommunicationAccess $access): StreamedResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 401);
        abort_if($access->readableChannels($user) === [], 403);

        $attachment = CommandCommunicationAttachment::query()->with('post')->where('public_id', $publicId)->first();
        abort_unless($attachment instanceof CommandCommunicationAttachment && $attachment->post !== null, 404);
        // Same 404 for missing, removed and other-audience attachments: no existence oracle.
        abort_unless($access->canSee($user, $attachment->post) && ! $attachment->post->isDeleted(), 404);
        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->storage_path), 404);

        $headers = [
            'Content-Type' => $attachment->mime_type,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];
        $name = $this->safeFilename($attachment);
        $inline = $attachment->isImage() && $request->boolean('inline');
        $headers['Content-Disposition'] = $this->disposition($inline ? 'inline' : 'attachment', $name);

        return response()->stream(function () use ($disk, $attachment): void {
            $stream = $disk->readStream($attachment->storage_path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, $headers);
    }

    private function safeFilename(CommandCommunicationAttachment $attachment): string
    {
        $extension = pathinfo($attachment->generated_filename, PATHINFO_EXTENSION);
        $base = pathinfo($attachment->original_filename, PATHINFO_FILENAME);
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($base)), '._-');

        return ($ascii !== '' ? $ascii : 'attachment').'.'.$extension;
    }

    private function disposition(string $type, string $filename): string
    {
        return $type.'; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename);
    }
}
