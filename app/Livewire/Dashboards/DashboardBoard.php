<?php

declare(strict_types=1);

namespace App\Livewire\Dashboards;

use App\Filament\Admin\Pages\EmployeeMessages;
use App\Filament\Pages\StationOperationsDashboard;
use App\Filament\Pages\VehicleCheckoutDashboard;
use App\Models\User;
use App\Services\Dashboards\DashboardAccess;
use App\Services\Dashboards\DashboardFilters;
use App\Services\Dashboards\DashboardReadModel;
use App\Services\Dashboards\DashboardSnapshot;
use App\Support\Dashboards\DashboardView;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Body of one admin dashboard (overview, vehicle checkout or station operations): operational-day
 * banner with honest freshness, stat cards, filter chips and the issue lists. Polls every 30 s while
 * visible; the read model caches a load for a few seconds per user. Rows open IssueActionPanel.
 */
final class DashboardBoard extends Component
{
    public const CHANGED_EVENT = 'dashboard-issues-changed';

    #[Locked]
    public string $dashboard = 'overview';

    #[Url(as: 'filter', except: 'all', history: true)]
    public string $filter = DashboardFilters::ALL;

    public int $rowsPerSource = DashboardReadModel::ROWS_PER_SOURCE;

    private bool $fresh = false;

    public function mount(string $dashboard): void
    {
        $view = DashboardView::tryFrom($dashboard);
        abort_if($view === null, 404);
        $this->dashboard = $view->value;
        $this->authorizeView();
        if (! DashboardFilters::isValid($view, $this->filter)) {
            $this->filter = DashboardFilters::ALL;
        }
    }

    public function setFilter(string $key): void
    {
        $this->filter = DashboardFilters::isValid($this->view(), $key) ? $key : DashboardFilters::ALL;
    }

    public function updatedFilter(): void
    {
        if (! DashboardFilters::isValid($this->view(), $this->filter)) {
            $this->filter = DashboardFilters::ALL;
        }
    }

    /** Reaches the rows past the first page, so every open item can be acted on from the board. */
    public function showMore(): void
    {
        $this->rowsPerSource = min($this->rowsPerSource + DashboardReadModel::ROWS_PER_SOURCE, DashboardReadModel::MAX_ROWS_PER_SOURCE);
    }

    /** The Refresh button: a real reload from the sources, bypassing the short cache. */
    public function refresh(): void
    {
        $this->fresh = true;
    }

    #[On(self::CHANGED_EVENT)]
    public function sourcesChanged(): void
    {
        $this->fresh = true;
    }

    public function render(): View
    {
        $this->authorizeView();
        $view = $this->view();
        /** @var User $user */
        $user = auth()->user();

        $snapshot = app(DashboardReadModel::class)->load($user, $view, $this->filter, $this->fresh, $this->rowsPerSource);
        $this->fresh = false;

        return view('livewire.dashboards.board', [
            'view' => $view,
            'snapshot' => $snapshot,
            'chips' => $this->visibleChips($view, $snapshot),
            'links' => $this->links($user),
        ]);
    }

    private function view(): DashboardView
    {
        return DashboardView::from($this->dashboard);
    }

    private function authorizeView(): void
    {
        abort_unless(DashboardAccess::canView(auth()->user(), $this->view()), 403);
    }

    /** @return list<array{key: string, label: string, countKey: string, kind: string, optional: bool, icon: string, count: int}> */
    private function visibleChips(DashboardView $view, DashboardSnapshot $snapshot): array
    {
        $chips = [];
        foreach (DashboardFilters::chips($view) as $chip) {
            $count = $snapshot->count($chip['countKey']);
            if ($chip['optional'] && $count === 0 && $this->filter !== $chip['key']) {
                continue;
            }
            $chips[] = [...$chip, 'count' => $count];
        }

        return $chips;
    }

    /** @return array{vehicle: ?string, station: ?string, messages: ?string} */
    private function links(User $user): array
    {
        $boards = DashboardAccess::canViewOperationsBoards($user);

        return [
            'vehicle' => $boards ? VehicleCheckoutDashboard::getUrl() : null,
            'station' => $boards ? StationOperationsDashboard::getUrl() : null,
            'messages' => $this->safeUrl(static fn (): ?string => EmployeeMessages::canAccess() ? EmployeeMessages::getUrl() : null),
        ];
    }

    private function safeUrl(callable $resolve): ?string
    {
        try {
            return $resolve();
        } catch (Throwable) {
            return null;
        }
    }
}
