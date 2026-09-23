<x-filament-widgets::widget>
    @php
        $formatType = static fn (string $type): string => str($type)->replace('_', ' ')->headline()->toString();
        $lastUpdatedLabel = filled($lastUpdated)
            ? \Illuminate\Support\Carbon::parse($lastUpdated)->timezone($operationalWindow['timezone'])->format('g:i:s A')
            : 'Waiting for first refresh';
    @endphp

    <div
        class="mbfd-station-command-board"
        data-last-updated="{{ $lastUpdated }}"
        wire:poll.20s="refreshBoard"
        wire:key="station-operations-command-board"
        x-data="{
            now: Date.now(),
            timer: null,
            init() { this.timer = window.setInterval(() => this.now = Date.now(), 5000) },
            destroy() { window.clearInterval(this.timer) },
            get stale() {
                const refreshed = Date.parse(this.$root.dataset.lastUpdated)
                return Number.isNaN(refreshed) || (this.now - refreshed) > 65000
            },
        }"
    >
        <section class="mbfd-command-rail" aria-label="Operational display status">
            <div>
                <p class="mbfd-command-eyebrow">Operational day</p>
                <p class="mbfd-command-window">{{ $operationalWindow['label'] }}</p>
                <p class="mbfd-command-timezone">America/New_York · records at 08:00 begin the new display day</p>
            </div>

            <div class="mbfd-command-live" role="status" aria-live="polite">
                <span class="mbfd-command-live-state" data-state="live" x-show="! stale">Live</span>
                <span class="mbfd-command-live-state" data-state="stale" x-show="stale" x-cloak>Stale</span>
                <span>Updated {{ $lastUpdatedLabel }}</span>
                <span wire:loading wire:target="refreshBoard">Refreshing…</span>
            </div>

            <div class="mbfd-command-actions">
                <button type="button" wire:click="refreshBoard" wire:loading.attr="disabled" wire:target="refreshBoard">Refresh</button>
                @if($displayMode)
                    <a href="{{ \App\Filament\Pages\Dashboard::getUrl() }}">Exit Display Mode</a>
                @endif
            </div>
        </section>

        <section class="mbfd-command-master" aria-label="Department overview">
            <article class="mbfd-command-master-card">
                <header>
                    <div><p class="mbfd-command-eyebrow">Active operational day</p><h2>Today’s Truck Checkouts</h2></div>
                    <span class="mbfd-command-count">{{ count($department['checkouts']) }}</span>
                </header>
                <div class="mbfd-command-record-list" tabindex="0" aria-label="All truck checkout records for the operational day">
                    @forelse($department['checkouts'] as $record)
                        <a class="mbfd-command-record" href="{{ $record['url'] }}">
                            <span><strong>{{ $record['label'] }}</strong><small>{{ $record['status'] }}</small></span>
                            <time datetime="{{ $record['timestamp'] }}">{{ $record['timeLabel'] }}</time>
                        </a>
                    @empty
                        <p class="mbfd-command-empty">No truck checkout records in this operational window.</p>
                    @endforelse
                </div>
            </article>

            <article class="mbfd-command-master-card">
                <header>
                    <div><p class="mbfd-command-eyebrow">Persistent until resolved</p><h2>New / Needs Attention</h2></div>
                    <span class="mbfd-command-count" data-tone="warning">{{ count($department['attention']) }}</span>
                </header>
                <div class="mbfd-command-record-list" tabindex="0" aria-label="All unresolved department attention items">
                    @forelse($department['attention'] as $record)
                        <a class="mbfd-command-record" data-tone="{{ $record['tone'] }}" href="{{ $record['url'] }}">
                            <span><strong>{{ $record['label'] }}</strong><small>{{ $formatType($record['type']) }} · {{ $record['status'] }}</small></span>
                            @if($record['timeLabel'])
                                <time datetime="{{ $record['timestamp'] }}">{{ $record['timeLabel'] }}</time>
                            @endif
                        </a>
                    @empty
                        <p class="mbfd-command-empty">No unresolved items currently need attention.</p>
                    @endforelse
                </div>
            </article>

            <article class="mbfd-command-master-card">
                <header>
                    <div><p class="mbfd-command-eyebrow">Authoritative fleet state</p><h2>Fleet &amp; Maintenance</h2></div>
                    <a class="mbfd-command-card-link" href="{{ $department['links']['apparatus'] }}">Open fleet</a>
                </header>
                <div class="mbfd-command-fleet-counts">
                    <a href="{{ $department['links']['apparatus'] }}"><strong>{{ $department['apparatus']['in_service'] }}</strong><span>In service</span></a>
                    <a data-tone="critical" href="{{ $department['links']['apparatus'] }}"><strong>{{ $department['apparatus']['out_of_service'] }}</strong><span>Out of service</span></a>
                    <a data-tone="warning" href="{{ $department['links']['apparatus'] }}"><strong>{{ $department['apparatus']['maintenance'] }}</strong><span>Maintenance</span></a>
                </div>
                <p class="mbfd-command-pm-summary">
                    PM: {{ $department['pm']['approaching'] }} approaching · {{ $department['pm']['due'] }} due
                    @if($department['pm']['critical'] > 0) · {{ $department['pm']['critical'] }} critically overdue @endif
                </p>
                <div class="mbfd-command-record-list mbfd-command-record-list-compact" tabindex="0" aria-label="Preventive maintenance attention items">
                    @forelse($department['pm']['records'] as $record)
                        <a class="mbfd-command-record" data-tone="{{ $record['tone'] }}" href="{{ $record['url'] }}">
                            <span><strong>{{ $record['label'] }}</strong><small>{{ $record['status'] }}</small></span>
                        </a>
                    @empty
                        <p class="mbfd-command-empty">No apparatus is approaching or beyond its PM threshold.</p>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="mbfd-command-stations" aria-labelledby="mbfd-stations-heading">
            <div class="mbfd-command-section-heading">
                <div><p class="mbfd-command-eyebrow">Stations 1 · 2 · 3 · 4 · 6</p><h2 id="mbfd-stations-heading">Station Operations</h2></div>
                <p>Select a station for complete activity, exception, apparatus, and workflow links.</p>
            </div>

            <div class="mbfd-command-station-grid">
                @forelse($stations as $station)
                    @php
                        $data = $stationData[$station['id']] ?? [];
                        $apparatus = $data['apparatus'] ?? ['records' => []];
                        $activity = $data['activity'] ?? [];
                        $exceptions = $data['exceptions'] ?? [];
                        $modalId = 'station-command-detail-'.$station['id'];
                    @endphp

                    <x-filament::modal
                        :id="$modalId"
                        :heading="'Station '.$station['station_number'].' Operations'"
                        :description="$station['name']"
                        width="7xl"
                        slide-over
                        sticky-header
                        wire:key="station-command-modal-{{ $station['id'] }}"
                    >
                        <x-slot name="trigger">
                            <button type="button" class="mbfd-command-station-card" data-attention="{{ ($data['attentionCount'] ?? 0) > 0 ? 'true' : 'false' }}" aria-label="Expand Station {{ $station['station_number'] }} operations">
                                <span class="mbfd-command-station-card-header">
                                    <span><span class="mbfd-command-eyebrow">Station</span><strong>{{ $station['station_number'] }}</strong></span>
                                    <span class="mbfd-command-attention-badge" data-tone="{{ ($data['attentionCount'] ?? 0) > 0 ? 'warning' : 'neutral' }}">{{ $data['attentionCount'] ?? 0 }} attention</span>
                                </span>
                                <span class="mbfd-command-station-fleet">
                                    <span><strong>{{ $apparatus['in_service'] ?? 0 }}</strong> in service</span>
                                    <span><strong>{{ $apparatus['out_of_service'] ?? 0 }}</strong> OOS</span>
                                    <span><strong>{{ $apparatus['maintenance'] ?? 0 }}</strong> maintenance</span>
                                </span>
                                <span class="mbfd-command-station-apparatus">
                                    @forelse(array_slice($apparatus['records'] ?? [], 0, 4) as $record)
                                        <span><strong>{{ $record['label'] }}</strong><small data-tone="{{ $record['tone'] }}">{{ $record['status'] }}</small></span>
                                    @empty
                                        <span class="mbfd-command-empty">No apparatus assigned.</span>
                                    @endforelse
                                </span>
                                <span class="mbfd-command-station-activity">
                                    <span class="mbfd-command-eyebrow">Latest activity</span>
                                    @forelse(array_slice($activity, 0, 3) as $record)
                                        <span><strong>{{ $record['label'] }}</strong><time datetime="{{ $record['timestamp'] }}">{{ $record['timeLabel'] }}</time></span>
                                    @empty
                                        <span class="mbfd-command-empty">No records in the active operational window.</span>
                                    @endforelse
                                </span>
                                <span class="mbfd-command-expand-cue">Expand station <span aria-hidden="true">→</span></span>
                            </button>
                        </x-slot>

                        <div class="mbfd-command-station-detail">
                            <section class="mbfd-command-detail-panel">
                                <header><h3>Assigned Apparatus</h3><a href="{{ $data['stationUrl'] }}">Full Station Profile</a></header>
                                <div class="mbfd-command-detail-list">
                                    @forelse($apparatus['records'] ?? [] as $record)
                                        <a href="{{ $record['url'] }}" data-tone="{{ $record['tone'] }}"><strong>{{ $record['label'] }}</strong><span>{{ $record['status'] }}</span></a>
                                    @empty
                                        <p class="mbfd-command-empty">No apparatus assigned.</p>
                                    @endforelse
                                </div>
                            </section>

                            <section class="mbfd-command-detail-panel">
                                <header><h3>Open Exceptions</h3><span>{{ count($exceptions) }}</span></header>
                                <div class="mbfd-command-detail-list">
                                    @forelse($exceptions as $record)
                                        <a href="{{ $record['url'] }}" data-tone="{{ $record['tone'] }}">
                                            <span><strong>{{ $record['label'] }}</strong><small>{{ $formatType($record['type']) }}</small></span><span>{{ $record['status'] }}</span>
                                        </a>
                                    @empty
                                        <p class="mbfd-command-empty">No open station exceptions.</p>
                                    @endforelse
                                </div>
                            </section>

                            <section class="mbfd-command-detail-panel mbfd-command-detail-panel-wide">
                                <header><h3>Operational-Day Activity</h3><span>{{ count($activity) }}</span></header>
                                <div class="mbfd-command-detail-list">
                                    @forelse($activity as $record)
                                        <a href="{{ $record['url'] }}" data-tone="{{ $record['tone'] }}">
                                            <span><strong>{{ $record['label'] }}</strong><small>{{ $formatType($record['type']) }} · {{ $record['status'] }}</small></span><time datetime="{{ $record['timestamp'] }}">{{ $record['timeLabel'] }}</time>
                                        </a>
                                    @empty
                                        <p class="mbfd-command-empty">No submissions or activity in this operational window.</p>
                                    @endforelse
                                </div>
                            </section>

                            <nav class="mbfd-command-workflow-links" aria-label="Station {{ $station['station_number'] }} workflows">
                                <a href="{{ $data['links']['requests'] }}">Station requests <strong>{{ $data['counts']['stationRequests'] ?? 0 }}</strong></a>
                                <a href="{{ $data['links']['tickets'] }}">Service tickets <strong>{{ $data['counts']['serviceTickets'] ?? 0 }}</strong></a>
                                <a href="{{ $data['links']['defects'] }}">Defects <strong>{{ $data['counts']['defects'] ?? 0 }}</strong></a>
                                <a href="{{ $data['links']['supplies'] }}">Supply requests <strong>{{ $data['counts']['supplyRequests'] ?? 0 }}</strong></a>
                                <a href="{{ $data['links']['inventorySubmissions'] }}">Inventory submissions</a>
                                <a href="{{ $data['links']['inspectionExceptions'] }}">Inspection exceptions</a>
                            </nav>
                        </div>
                    </x-filament::modal>
                @empty
                    <p class="mbfd-command-empty">No configured operational stations are available.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-filament-widgets::widget>
