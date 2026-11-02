<li wire:key="review-{{ $key }}-{{ $member['id'] }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
    @if ($pickable)
        <input type="checkbox" wire:model.live="planSelection" value="{{ $member['id'] }}" aria-label="Check {{ $member['name'] }}" />
    @endif
    <span class="min-w-0 flex-1"><strong>{{ $member['name'] }}</strong> · {{ $member['rank'] }} · ID {{ $member['employee_id'] }}@if ($member['exception']) · <x-filament::badge color="warning" class="inline-flex">Exception</x-filament::badge>@endif @if ($detail)<span class="block">{{ $detail }}</span>@endif</span>
    <x-filament::button color="gray" size="sm" wire:click="removeMember({{ $member['id'] }})" aria-label="Remove {{ $member['name'] }}">Remove</x-filament::button>
</li>
