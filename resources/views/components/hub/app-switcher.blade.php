@props(['navigation', 'module' => 'Apps', 'id' => 'hub-apps'])
@php
    // The server list carries Home. Guests and hand-built lists do not, and the switcher always offers it.
    $applications = collect($navigation['applications']);
    if (! $applications->contains('key', 'home')) {
        $applications->prepend(['key' => 'home', 'label' => 'Home', 'href' => '/']);
    }
    // The most specific path wins, so Email (/admin/email) is current on Email pages and Admin is not.
    $current = $applications
        ->reject(fn ($application) => str_starts_with($application['href'], 'https://'))
        ->filter(fn ($application) => $application['href'] === '/' ? request()->is('/') : request()->is(ltrim($application['href'], '/'), ltrim($application['href'], '/').'/*'))
        ->sortByDesc(fn ($application) => strlen($application['href']))
        ->first();
@endphp
<details class="hub-shell-menu hub-app-switcher" data-hub-menu>
    <summary class="hub-shell-control" aria-haspopup="menu" aria-controls="{{ $id }}" aria-label="{{ $module }} · Switch application">
        <x-heroicon-o-squares-2x2 class="hub-shell-icon" aria-hidden="true" />
        <span>{{ $module }}</span>
        <x-heroicon-o-chevron-down class="hub-shell-chevron" aria-hidden="true" />
    </summary>
    <div id="{{ $id }}" class="hub-shell-popover" role="menu" aria-label="Applications">
        @foreach($applications as $application)
            <x-hub.menu-item :href="$application['href']" :label="$application['label']" :current="($current['key'] ?? null) === $application['key']" />
        @endforeach
    </div>
</details>
