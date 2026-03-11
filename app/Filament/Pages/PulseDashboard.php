<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class PulseDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationGroup = 'Monitoring';

    protected static ?string $navigationLabel = 'Laravel Pulse';

    protected static ?string $title = 'Laravel Pulse';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.pulse-dashboard';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }
}
