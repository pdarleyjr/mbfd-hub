<?php

namespace App\Filament\Training\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class Settings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $view = 'filament.training.pages.settings';

    protected static ?string $title = 'Settings';

    protected static ?int $navigationSort = 100;

    // Hide from sidebar - accessible via user menu
    protected static bool $shouldRegisterNavigation = false;

    public function getVapidPublicKey(): string
    {
        return config('webpush.vapid.public_key') ?? '';
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
