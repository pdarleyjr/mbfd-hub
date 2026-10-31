<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\PushDeviceRemovals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Does the Hub know this browser's subscription for the signed-in member? Lets a page repair its own device without
 * writing on every visit. Takes only a hash, so the private endpoint is never in a URL or a log.
 */
class PushDeviceStateController extends Controller
{
    public function __invoke(Request $request, PushDeviceRemovals $removals): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate(['hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/']]);

        $device = $user->pushSubscriptions()->get()
            ->first(fn ($candidate): bool => hash_equals(hash('sha256', (string) $candidate->endpoint), $validated['hash']));

        return response()
            ->json([
                'known' => $device !== null,
                // Enrolled before the Hub recorded the device: one quiet re-save fills in its name.
                'refresh' => $device !== null && $device->device_label === null,
                'removed' => $device === null && $removals->wasRemoved($validated['hash']),
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
