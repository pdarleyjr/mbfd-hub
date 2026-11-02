<div class="space-y-3">
    <p class="text-sm text-gray-500">Latest 100 changes. Tracking values and personnel contact details are omitted.</p>
    @if($this->canExportPersonnelHistory())
        <div class="flex gap-2">
            <x-filament::button color="gray" wire:click="exportPersonnelHistory('xlsx')" wire:loading.attr="disabled" wire:target="exportPersonnelHistory">Export Excel</x-filament::button>
            <x-filament::button color="gray" wire:click="exportPersonnelHistory('pdf')" wire:loading.attr="disabled" wire:target="exportPersonnelHistory">Export PDF</x-filament::button>
        </div>
    @endif
    <div>
        <table data-hub-table class="w-full text-sm text-left">
            <thead><tr><th class="p-2">When</th><th class="p-2">Administrator</th><th class="p-2">Change</th><th class="p-2">Fields</th></tr></thead>
            <tbody>
                @forelse ($events as $event)
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <td class="p-2 whitespace-nowrap">{{ $event->created_at->copy()->timezone(config('hub.timezone'))->format('M j, Y g:i A') }}</td>
                        <td class="p-2">{{ $event->actor?->name ?? 'Former administrator' }}</td>
                        <td class="p-2">{{ str($event->action)->replace('_', ' ')->headline() }}</td>
                        <td class="p-2">{{ implode(', ', $event->metadata['fields'] ?? []) }}</td>
                    </tr>
                @empty
                    <tr><td class="p-2" colspan="4">No changes recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
