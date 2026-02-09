<?php

namespace App\Filament\Training\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $title = 'Training Dashboard';

    protected static string $routePath = '/';
}
