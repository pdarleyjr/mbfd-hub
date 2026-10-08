<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Http\Controllers;

use Illuminate\Http\Request;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Services\ManualDeliveryService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class ManualDeliveryController
{
    public function show(Request $request, string $slug, ManualDeliveryService $deliveries): Response
    {
        $data = $request->validate(['edition' => ['required', 'integer', 'min:1'], 'download' => ['sometimes', 'in:1']]);
        $manual = Manual::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();
        abort_unless((int) $data['edition'] === $manual->active_edition_id, 404);
        $delivery = $deliveries->current($manual, verifyBytes: true);
        abort_if($delivery === null, 404);
        abort_unless($delivery['edition_id'] === (int) $data['edition'], 404);
        $filename = $slug === 'sogs' ? 'MBFD-Complete-SOGs.pdf' : 'MBFD-Complete-Medical-Protocols.pdf';
        $disposition = (new ResponseHeaderBag)->makeDisposition(isset($data['download']) ? 'attachment' : 'inline', $filename);

        // BinaryFileResponse supplies Range / If-Range without exposing any private storage path.
        return response()->file($delivery['path'], ['Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition, 'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive'])->setPrivate()->setEtag($delivery['sha256']);
    }
}
