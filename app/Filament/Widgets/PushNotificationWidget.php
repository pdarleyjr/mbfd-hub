<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class PushNotificationWidget extends Widget
{
    protected static string $view = 'filament.widgets.push-notification-widget';
    
    protected int | string | array $columnSpan = 'full';
    
    protected static ?int $sort = 1;

    public function getVapidPublicKey(): string
    {
        return config('webpush.vapid.public_key', '');
    }

    public function isSubscribed(): bool
    {
        $user = Auth::user();
        return $user && $user->pushSubscriptions()->exists();
    }

    public function getSubscriptionCount(): int
    {
        $user = Auth::user();
        return $user ? $user->pushSubscriptions()->count() : 0;
    }
}
