<x-filament-panels::page>
    <div class="mb-6">
        <x-filament::section>
            <x-slot name="heading">
                {{ $this->record->unit_id }} - {{ $this->record->make }} {{ $this->record->model }}
            </x-slot>
            <x-slot name="description">
                @if($this->record->vehicle_number)
                    Vehicle #{{ $this->record->vehicle_number }} |
                @endif
                Status: {{ $this->record->status }}
                @if($this->record->location)
                    | Location: {{ $this->record->location }}
                @endif
            </x-slot>
            
            <div class="flex gap-4">
                <x-filament::button
                    tag="a"
                    href="/daily/{{ $this->record->id }}"
                    target="_blank"
                    color="success"
                    icon="heroicon-o-play-circle"
                >
                    Start New Inspection
                </x-filament::button>
                
                <x-filament::button
                    tag="a"
                    href="{{ \App\Filament\Resources\ApparatusResource::getUrl('edit', ['record' => $this->record]) }}"
                    color="gray"
                    icon="heroicon-o-pencil"
                >
                    Edit Apparatus
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
