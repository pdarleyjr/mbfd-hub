<div class="space-y-4" data-employee-additional-details>
    @if (! $hasFields)
        <p class="text-sm text-gray-600 dark:text-gray-400">No additional profile fields are set up. Administrators add them from the Personnel Directory under Columns.</p>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Source: Personnel Directory columns placed on profiles. Changes here and in the directory are the same record and appear in the directory's History.
        </p>
        {{-- Not a <form>: this panel sits inside the profile's own form, and only its own button saves it. --}}
        <div class="space-y-4">
            {{ $this->form }}
            @error('data.conflict')
                <p class="text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $message }}</p>
            @enderror
            @if ($editable)
                <x-filament::button type="button" wire:click="save">Save additional details</x-filament::button>
            @endif
        </div>
    @endif
</div>
