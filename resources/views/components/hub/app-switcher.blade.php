@props(['navigation', 'module' => 'Apps', 'id' => 'hub-apps'])
<details class="hub-shell-menu hub-app-switcher" data-hub-menu>
    <summary class="hub-shell-control" aria-haspopup="menu" aria-controls="{{ $id }}" aria-label="{{ $module }} · Switch application">
        <x-heroicon-o-squares-2x2 class="hub-shell-icon" aria-hidden="true" />
        <span>{{ $module }}</span>
        <x-heroicon-o-chevron-down class="hub-shell-chevron" aria-hidden="true" />
    </summary>
    <div id="{{ $id }}" class="hub-shell-popover" role="menu" aria-label="Applications">
        <a role="menuitem" href="/" class="hub-shell-menuitem">Home</a>
        @foreach($navigation['applications'] as $application)
            <a role="menuitem" href="{{ $application['href'] }}" class="hub-shell-menuitem" @if(str_starts_with($application['href'], 'https://')) target="_blank" rel="noopener" @endif @if(request()->is($application['key'], $application['key'].'/*')) aria-current="page" @endif>{{ $application['label'] }}@if(str_starts_with($application['href'], 'https://'))<span class="sr-only"> (opens in a new tab)</span>@endif</a>
        @endforeach
    </div>
</details>
