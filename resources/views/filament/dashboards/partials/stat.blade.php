{{-- Stat card with an exact count from the sources. A card links only when there is a real destination. --}}
@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if ($href) href="{{ $href }}" @endif class="mbfd-dash-stat" data-tone="{{ $tone }}" data-stat="{{ $key }}">
    <span class="mbfd-dash-stat__icon" aria-hidden="true"><x-filament::icon :icon="$icon" class="mbfd-dash-stat__svg" /></span>
    <span class="mbfd-dash-stat__text">
        <span class="mbfd-dash-stat__label">{{ $label }}</span>
        <span class="mbfd-dash-stat__value">{{ number_format($value) }}</span>
        <span class="mbfd-dash-stat__note">{{ $note }}</span>
    </span>
    @if ($href)
        <x-filament::icon icon="heroicon-m-chevron-right" class="mbfd-dash-stat__chevron" />
    @endif
</{{ $tag }}>
