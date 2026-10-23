<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApparatusInventoryCorrection;
use App\Models\User;
use App\Services\Inventory\InventoryCorrectionService;
use App\Support\Fleet\InventoryCorrectionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The photo a member attached to an inventory correction: Logistics and the reporter only. */
final class InventoryCorrectionPhotoController
{
    public function __invoke(Request $request, ApparatusInventoryCorrection $correction): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User
            && (InventoryCorrectionAccess::canReview($user) || InventoryCorrectionAccess::owns($user, $correction)), 403);
        $path = $correction->photo_path;
        $disk = Storage::disk(InventoryCorrectionService::PHOTO_DISK);
        abort_unless(is_string($path) && str_starts_with($path, 'inventory-corrections/') && $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
