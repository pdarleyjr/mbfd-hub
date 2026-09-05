<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\CloudflareUsageBudget;
use App\Models\OutboundEmail;
use Filament\Pages\Page;

final class CommunicationsUsage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'Usage';

    protected static string $view = 'filament.pages.communications-usage';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('admin.communications.view') ?? false;
    }

    public function getBudget(): ?CloudflareUsageBudget
    {
        return CloudflareUsageBudget::query()->latest('cycle_start')->first();
    }

    public function getReservedUnits(): int
    {
        return (int) OutboundEmail::query()
            ->whereNotNull('budget_reserved_at')
            ->whereNull('budget_released_at')
            ->sum('chargeable_budget_units');
    }
}
