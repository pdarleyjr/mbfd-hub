{{-- Vehicle Checkout (2 of 3) and Station Operations (3 of 3) dashboards. --}}
@php($dashboardView = $this::dashboardView())
<x-filament-panels::page class="mbfd-dash-page">
    <div class="mbfd-dash">
        <livewire:dashboards.dashboard-board :dashboard="$dashboardView->value" />
        @include('filament.dashboards.partials.pager', ['current' => $dashboardView])
        <livewire:dashboards.issue-action-panel />
    </div>
</x-filament-panels::page>
