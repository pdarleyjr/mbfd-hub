{{-- Dashboard page heading: title + purpose on the left, today's date and greeting on the right. --}}
<header class="mbfd-dash-header">
    <div class="mbfd-dash-header__text">
        <h1 class="mbfd-dash-header__title">{{ $heading }}</h1>
        @if (filled($subheading))
            <p class="mbfd-dash-header__sub">{{ $subheading }}</p>
        @endif
    </div>
    <div class="mbfd-dash-header__meta">
        <p class="mbfd-dash-header__date">{{ $date }}</p>
        <p class="mbfd-dash-header__greeting">{{ $greeting }}</p>
    </div>
    @if (count($actions))
        <div class="mbfd-dash-header__actions">
            <x-filament-actions::actions :actions="$actions" />
        </div>
    @endif
</header>
