<div class="mail-topbar-slot" wire:poll.60s.visible data-mail-badge data-unread="{{ $count }}">
    @if ($allowed)
        <x-filament::icon-button
            tag="a"
            :href="$url"
            icon="heroicon-o-envelope"
            :badge="$count > 0 ? ($count > 99 ? '99+' : (string) $count) : null"
            badge-color="danger"
            :label="$label"
            :aria-label="$label"
            class="fi-topbar-mail-btn"
        />
    @endif
</div>
