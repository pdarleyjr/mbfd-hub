<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PushSubscriptionController extends Controller
{
    /**
     * Store a new push subscription for the authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string|max:500',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        $user = Auth::user();
        
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Log::info('Push subscription store requested', [
            'user_id' => $user->id,
            'endpoint' => $validated['endpoint'],
            'p256dh_length' => strlen($validated['keys']['p256dh']),
            'auth_length' => strlen($validated['keys']['auth']),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        try {
            // Update or create subscription
            $user->updatePushSubscription(
                $validated['endpoint'],
                $validated['keys']['p256dh'],
                $validated['keys']['auth']
            );

            $subscriptionCount = $user->pushSubscriptions()->count();

            Log::info('Push subscription saved successfully', [
                'user_id' => $user->id,
                'endpoint' => $validated['endpoint'],
                'subscription_count' => $subscriptionCount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Push subscription saved successfully',
                'subscriptionCount' => $subscriptionCount,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Push subscription save failed', [
                'user_id' => $user->id,
                'endpoint' => $validated['endpoint'],
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save push subscription',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a push subscription for the authenticated user.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
        ]);

        $user = Auth::user();
        
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Log::info('Push subscription delete requested', [
            'user_id' => $user->id,
            'endpoint' => $validated['endpoint'],
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        try {
            $user->deletePushSubscription($validated['endpoint']);

            $subscriptionCount = $user->pushSubscriptions()->count();

            Log::info('Push subscription removed successfully', [
                'user_id' => $user->id,
                'endpoint' => $validated['endpoint'],
                'subscription_count' => $subscriptionCount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Push subscription removed successfully',
                'subscriptionCount' => $subscriptionCount,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Push subscription delete failed', [
                'user_id' => $user->id,
                'endpoint' => $validated['endpoint'],
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove push subscription',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Get VAPID public key for client-side subscription.
     */
    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'publicKey' => config('webpush.vapid.public_key'),
        ]);
    }
}
