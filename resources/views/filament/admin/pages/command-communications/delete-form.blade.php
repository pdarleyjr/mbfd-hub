<form wire:submit="confirmDelete" class="mt-3 space-y-2 rounded-lg border border-danger-200 bg-danger-50 p-3" aria-label="Remove this message">
    <p class="text-sm font-medium text-gray-900">
        Remove this message? Everyone will see that it was removed, but not what it said. The reason is kept in the audit record.
    </p>
    <label for="delete-reason-{{ $item->public_id }}" class="text-sm font-medium text-gray-900">Reason</label>
    <x-filament::input.wrapper>
        <textarea id="delete-reason-{{ $item->public_id }}" wire:model="deleteReason" rows="2" maxlength="500" class="block w-full border-none bg-transparent px-3 py-2 text-sm text-gray-900 focus:ring-0"></textarea>
    </x-filament::input.wrapper>
    @error('deleteReason')
        <p class="text-sm text-danger-600" role="alert">{{ $message }}</p>
    @enderror
    <div class="flex gap-2">
        <x-filament::button type="submit" color="danger" size="sm" class="min-h-[44px] min-w-[44px]" wire:loading.attr="disabled" wire:target="confirmDelete">Remove message</x-filament::button>
        <x-filament::button type="button" color="gray" size="sm" class="min-h-[44px] min-w-[44px]" wire:click="cancelDelete">Keep it</x-filament::button>
    </div>
</form>
