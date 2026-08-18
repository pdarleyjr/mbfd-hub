<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PersonnelRequestAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonnelRequestAttachmentController extends Controller
{
    public function __invoke(PersonnelRequestAttachment $attachment): StreamedResponse
    {
        abort_unless(auth()->user()?->hasAnyRole(['super_admin', 'admin', 'logistics_admin']), 403);
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
