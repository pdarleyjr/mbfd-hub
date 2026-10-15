<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <x-filament::section>
        <p class="text-sm text-gray-600">Your valid training attendance, including archived classes. Voided entries are excluded. Recorded rank and shift reflect the original attendance, and corrections are identified. Provisional rosters remain subject to officer certification. Contact the Training Division to request a correction or an official record.</p>
    </x-filament::section>
    {{ $this->table }}
</x-filament-panels::page>
