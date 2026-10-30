{{--
    /admin. Display mode (?display=1, wall screens) renders Filament's dashboard page exactly as before
    (StationOperationsHubWidget only, no personal widgets, no action panel). Otherwise: Admin Overview, 1 of 3.
--}}
@if ($this->isDisplayMode())
    @include('filament-panels::pages.dashboard')
@else
    <x-filament-panels::page class="fi-dashboard-page mbfd-dash-page">
        <div class="mbfd-dash">
            <livewire:dashboards.dashboard-board dashboard="overview" />
            @include('filament.dashboards.partials.pager', ['current' => \App\Support\Dashboards\DashboardView::Overview])
            <livewire:dashboards.issue-action-panel />
        </div>
    </x-filament-panels::page>
@endif
