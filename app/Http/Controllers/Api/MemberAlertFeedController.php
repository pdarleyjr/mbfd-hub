<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\ForegroundAlertPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in member's newest in-app alerts, for pages that have no Filament
 * bell (Hub home and Daily Checkout). One shared, visibility-aware poller per
 * device reads it (resources/js/pwa/foreground-alerts.ts).
 *
 * Read-only: it never marks an alert read. The bell stays the record.
 */
final class MemberAlertFeedController extends Controller
{
    public function __invoke(Request $request, ForegroundAlertPresenter $presenter): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Same query the bell uses (Filament's format only), newest first.
        $alerts = $user->notifications()
            ->where('data->format', 'filament')
            ->limit(ForegroundAlertPresenter::SNAPSHOT_SIZE)
            ->get();

        $snapshot = $presenter->snapshot($alerts);

        return response()
            ->json([
                ...$snapshot,
                'unread_count' => $user->unreadNotifications()->where('data->format', 'filament')->count(),
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
