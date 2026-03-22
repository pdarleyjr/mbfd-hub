<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;

class Settings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $view = 'filament.pages.settings';

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

    public function canManageUsers(): bool
    {
        // Check if user has admin permissions
        return auth()->check();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manageUsers')
                ->label('Manage Users')
                ->icon('heroicon-o-users')
                ->url(route('filament.admin.resources.users.index'))
                ->visible(fn () => $this->canManageUsers()),
        ];
    }
}
