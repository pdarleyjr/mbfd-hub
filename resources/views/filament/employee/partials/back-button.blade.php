@php
    $parent = \Livewire\Livewire::current() instanceof \App\Filament\Employee\Pages\EmployeeDashboard ? '/' : '/employee/dashboard';
    $destination = \App\Support\HubNavigation::backUrl($parent);
    $destinationPath = parse_url($destination, PHP_URL_PATH);
    $label = $destinationPath === $parent ? ($parent === '/' ? 'Back to Hub home' : 'Back to Employee dashboard') : 'Back';
    if (preg_match('#^/daily/stations/[0-9]+$#', $destinationPath)) {
        $label = 'Back to Station';
    }
@endphp
<div class="employee-global-back mb-3">
    <x-filament::button tag="a" :href="$destination" :aria-label="$label" color="gray" icon="heroicon-o-arrow-left" class="min-h-11" data-hub-back>
        <span class="hub-back-short" aria-hidden="true">Back</span>
        <span class="hub-back-label">{{ $label }}</span>
    </x-filament::button>
</div>
