<x-filament-panels::page>
    <form wire:submit="send" class="space-y-6">
        {{ $this->form }}
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $this->recipientCount() }} unique recipients. Provider acceptance is separate from confirmed delivery.</p>
        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="send">Send email</x-filament::button>
    </form>
</x-filament-panels::page>
