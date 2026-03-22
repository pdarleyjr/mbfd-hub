<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\TestPushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TestNotificationController extends Controller
{
    public function sendTestNotification(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        // Check if user has push subscriptions
        if ($user->pushSubscriptions()->count() === 0) {
            return response()->json(['error' => 'No push subscriptions found. Please enable notifications first.'], 400);
        }
        
        $user->notify(new TestPushNotification());
        
        return response()->json([
            'success' => true,
            'message' => 'Test notification sent successfully!'
        ]);
    }
}
