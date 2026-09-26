@if ($destination)
    <x-filament::button tag="a" :href="$destination['url']" :aria-label="$destination['label']" color="gray" icon="heroicon-o-arrow-left" class="min-h-11" data-hub-back>
        <span class="hub-back-short" aria-hidden="true">Back</span>
        <span class="hub-back-label">{{ $destination['label'] }}</span>
    </x-filament::button>
@endif
