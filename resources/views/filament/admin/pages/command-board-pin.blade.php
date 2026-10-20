{{-- Filament infolist components only: no Tailwind utilities, inline styles, or colour literals. --}}
<x-filament-panels::page>
    <div data-testid="command-board-pin-status">
        {{ $this->statusInfolist }}
    </div>
</x-filament-panels::page>
