<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\HasDatasetExportActions;
use App\Models\CloudflareUsageBudget;
use App\Models\User;
use App\Services\Communications\CloudflareCostGuard;
use App\Services\Exports\ExportDataset;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

final class CommunicationsUsage extends Page
{
    use HasDatasetExportActions;

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Communications Usage';

    protected static string $view = 'filament.pages.communications-usage';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('admin.communications.view') ?? false;
    }

    public function canExportData(User $actor): bool
    {
        return $actor->can('admin.communications.view');
    }

    protected function getHeaderActions(): array
    {
        return [$this->datasetExportAction()];
    }

    protected function exportDataset(User $actor): ExportDataset
    {
        abort_unless($this->canExportData($actor), 403);
        $budget = $this->getBudget();
        if ($budget === null) {
            return ExportDataset::fromRows($actor, 'Communications Usage', ['Metric', 'Value', 'Unit'], [
                ['Snapshot status', 'No reconciled current billing-cycle snapshot; outbound email is blocked.', null],
            ], 'Current billing cycle');
        }

        return ExportDataset::fromRows($actor, 'Communications Usage', ['Metric', 'Value', 'Unit'], [
            ['Billing cycle start', $budget->cycle_start, \App\Support\HubTimezone::NAME],
            ['Billing cycle end', $budget->cycle_end, \App\Support\HubTimezone::NAME],
            ['Provider usage', $budget->provider_chargeable_used, 'chargeable destinations'],
            ['Current-cycle Hub reservations', $this->getReservedUnits(), 'destinations'],
            ['Safe ceiling', min(2850, $budget->hub_safe_ceiling, (int) config('communications.cloudflare.safe_email_ceiling', 2850)), 'destinations'],
            ['Last reconciled', $budget->reconciled_at, \App\Support\HubTimezone::NAME],
            ['Provider daily usage', $budget->provider_daily_used, 'destinations'],
            ['Provider daily quota', $budget->provider_daily_quota, 'destinations'],
            ['Worker requests', $budget->worker_requests_used, 'requests'],
            ['Worker request threshold', $budget->worker_request_threshold, 'requests'],
            ['Worker CPU time', $budget->worker_cpu_ms_used, 'ms'],
            ['Worker CPU threshold', $budget->worker_cpu_ms_threshold, 'ms'],
        ], 'Current billing cycle', [
            'Billing cycle: '.CarbonImmutable::parse($budget->cycle_start)->timezone((string) config('hub.timezone'))->format('Y-m-d H:i T')
                .' to '.CarbonImmutable::parse($budget->cycle_end)->timezone((string) config('hub.timezone'))->format('Y-m-d H:i T'),
        ]);
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
