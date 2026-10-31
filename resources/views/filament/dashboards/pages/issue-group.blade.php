{{-- All of one apparatus, station or member's issues. --}}
<x-filament-panels::page class="mbfd-dash-page">
    <div class="mbfd-dash">
        <livewire:dashboards.issue-group-board :group="$this->group" />
        <livewire:dashboards.issue-action-panel />
    </div>
</x-filament-panels::page>
