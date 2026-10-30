{{--
    Dashboard body. Freshness is honest: "Live" only while this component's polls succeed, "Stale" once a
    refresh window (30 s + 15 s grace) passes without one, "Offline" when the browser reports no network.
    "Updated" is the time the data shown was loaded on the server.
--}}
@php
    $window = $snapshot->window->toDisplayArray();
    $activeChip = collect($chips)->firstWhere('key', $snapshot->filter);
    $layout = \App\Services\Dashboards\DashboardLayout::for(
        $view,
        $snapshot,
        $links,
        $snapshot->filter !== 'all' ? ($activeChip['label'] ?? null) : null,
    );
@endphp
<div class="mbfd-dash-board" data-dashboard="{{ $view->value }}"
    wire:poll.30s.visible
    x-data="{
        online: navigator.onLine,
        lastOk: Date.now(),
        failed: false,
        now: Date.now(),
        timer: null,
        get state() {
            if (! this.online) return 'offline';
            if (this.failed || this.now - this.lastOk > 45000) return 'stale';
            return 'live';
        },
        init() {
            this.timer = setInterval(() => { this.now = Date.now() }, 5000);
            const id = this.$wire.$id;
            window.Livewire.hook('commit', ({ component, succeed, fail }) => {
                if (component.id !== id) return;
                succeed(() => { this.lastOk = Date.now(); this.now = this.lastOk; this.failed = false; });
                fail(() => { this.failed = true; });
            });
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible' && Date.now() - this.lastOk > 30000) this.$wire.$refresh();
            });
        },
        destroy() { clearInterval(this.timer); },
    }"
    x-on:online.window="online = true"
    x-on:offline.window="online = false; now = Date.now()">

    <section class="mbfd-dash-banner" aria-labelledby="mbfd-dash-day-{{ $view->value }}">
        <div class="mbfd-dash-banner__day">
            <p class="mbfd-dash-banner__eyebrow" id="mbfd-dash-day-{{ $view->value }}">Operational day</p>
            <p class="mbfd-dash-banner__range">
                <time datetime="{{ $window['start'] }}">{{ $snapshot->window->start->format('D, M j · g:i A') }}</time>
                <span aria-hidden="true">–</span><span class="sr-only">to</span>
                <time datetime="{{ $window['end'] }}">{{ $snapshot->window->end->format('D, M j · g:i A') }}</time>
            </p>
            <p class="mbfd-dash-banner__tz">{{ $window['timezone'] }} · records at 08:00 begin the new display day</p>
        </div>
        <div class="mbfd-dash-banner__status">
            <span class="mbfd-dash-live" x-cloak x-bind:data-state="state" data-freshness role="status" aria-live="polite">
                <span class="mbfd-dash-live__dot" aria-hidden="true"></span>
                <span x-text="state === 'live' ? 'Live' : (state === 'offline' ? 'Offline' : 'Stale')"></span>
            </span>
            <span class="mbfd-dash-banner__updated">Updated <time datetime="{{ $snapshot->loadedAt->toIso8601String() }}" data-updated>{{ $snapshot->loadedAt->format('g:i:s A') }}</time></span>
            <button type="button" class="mbfd-dash-refresh" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh">
                <x-filament::icon icon="heroicon-m-arrow-path" class="mbfd-dash-refresh__icon" wire:loading.class="is-spinning" wire:target="refresh" />
                <span>Refresh</span>
            </button>
        </div>
    </section>

    @if ($snapshot->failedSources !== [])
        <p class="mbfd-dash-notice" role="status">
            Some items could not be loaded just now, so these lists may be incomplete. They will retry on the next refresh.
        </p>
    @endif

    @if ($chips !== [])
        <div class="mbfd-dash-chips" role="group" aria-label="Filter {{ strtolower($view->label()) }} items">
            @foreach ($chips as $chip)
                <button type="button" class="mbfd-dash-chip" wire:key="chip-{{ $chip['key'] }}"
                    wire:click="setFilter('{{ $chip['key'] }}')"
                    aria-pressed="{{ $snapshot->filter === $chip['key'] ? 'true' : 'false' }}">
                    <x-filament::icon :icon="$chip['icon']" class="mbfd-dash-chip__icon" />
                    <span>{{ $chip['label'] }}</span>
                    {{-- Counts are OPEN items; the list below also keeps items closed earlier this operational day. --}}
                    <span class="mbfd-dash-chip__count">· {{ number_format($chip['count']) }} open</span>
                </button>
            @endforeach
        </div>
    @endif

    <div class="mbfd-dash-stats">
        @foreach ($layout['stats'] as $stat)
            @include('filament.dashboards.partials.stat', $stat)
        @endforeach
    </div>

    <div @class(['mbfd-dash-body', 'mbfd-dash-body--lanes' => $layout['side'] === [], 'mbfd-dash-body--split' => $layout['side'] !== []])>
        <div class="mbfd-dash-main">
            @foreach ($layout['main'] as $card)
                @include('filament.dashboards.partials.list-card', $card)
            @endforeach
        </div>
        @if ($layout['side'] !== [])
            <div class="mbfd-dash-side">
                @foreach ($layout['side'] as $card)
                    @if ($card['type'] === 'queue')
                        @include('filament.dashboards.partials.stage-queue', $card)
                    @else
                        @include('filament.dashboards.partials.list-card', $card)
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</div>
