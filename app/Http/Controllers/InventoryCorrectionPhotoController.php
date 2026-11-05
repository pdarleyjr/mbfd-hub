<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent;
use App\Models\User;
use App\Services\Inventory\InventoryCorrectionService;
use App\Support\Fleet\InventoryCorrectionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A photo a member attached to an inventory correction: Logistics and the member who took it only.
 * Members added to a request later never see another member's photo, and vice versa.
 */
final class InventoryCorrectionPhotoController
{
    public function __invoke(Request $request, ApparatusInventoryCorrection $correction): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 404);
        $reviewer = InventoryCorrectionAccess::canReview($user);
        if ($request->filled('event')) {
            // ?event=: the photo a member attached when reporting the same change.
            $event = ApparatusInventoryCorrectionEvent::query()
                ->whereKey((int) $request->query('event'))
                ->where('apparatus_inventory_correction_id', $correction->getKey())
                ->where('event_type', ApparatusInventoryCorrectionEvent::JOINED)
                ->first();
            abort_unless($event !== null && ($reviewer || ($user->isAuthenticationAllowed() && (int) $event->actor_user_id === (int) $user->getKey())), 404);
            $path = is_array($event->details) ? ($event->details['photo_path'] ?? null) : null;
        } else {
            abort_unless($reviewer || ($user->isAuthenticationAllowed() && InventoryCorrectionAccess::reported($user, $correction)), 404);
            $path = $correction->photo_path;
        }
        $disk = Storage::disk(InventoryCorrectionService::PHOTO_DISK);
        abort_unless(is_string($path) && str_starts_with($path, 'inventory-corrections/') && $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
