<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\CloudflareUsageBudget;
use App\Services\Communications\CloudflareCostGuard;
use Carbon\CarbonImmutable;
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
        $budgets = CloudflareUsageBudget::query()
            ->where('cycle_start', '<=', now())
            ->where('cycle_end', '>', now())
            ->get();
        $budget = $budgets->count() === 1 ? $budgets->first() : null;

        return $budget?->provider_account_id === config('communications.cloudflare.account_id') ? $budget : null;
    }

    public function getReservedUnits(): int
    {
        $budget = $this->getBudget();

        return $budget === null ? 0 : app(CloudflareCostGuard::class)->localReservedOrAcceptedUnits(
            now(), since: CarbonImmutable::parse($budget->cycle_start), carryUnresolved: true,
        );
    }
}
