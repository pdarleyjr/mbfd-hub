<x-filament-panels::page>
    @if($this->getHeaderWidgets())
        <x-filament-widgets::widgets :widgets="$this->getHeaderWidgets()" :columns="$this->getHeaderWidgetsColumns()" />
    @endif
</x-filament-panels::page>