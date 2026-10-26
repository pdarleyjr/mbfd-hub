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
            now: performance.now(),
            receivedAt: performance.now(),
            receivedStamp: null,
            timer: null,
            observeRefresh() {
                const stamp = this.$root.dataset.lastUpdated
                if (stamp !== this.receivedStamp) {
                    this.receivedStamp = stamp
                    this.receivedAt = performance.now()
                }
            },
            init() {
                this.observeRefresh()
                this.timer = window.setInterval(() => { this.observeRefresh(); this.now = performance.now() }, 5000)
            },
            destroy() { window.clearInterval(this.timer) },
            get stale() {
                const refreshed = Date.parse(this.$root.dataset.lastUpdated)
                return Number.isNaN(refreshed) || (this.now - this.receivedAt) > 65000
            },
        }"
    >
        <section class="mbfd-command-rail" aria-label="Operational display status">
            <div>
                <p class="mbfd-command-eyebrow">Operational day</p>
                <p class="mbfd-command-window">{{ $operationalWindow['label'] }}</p>
                <p class="mbfd-command-timezone">{{ $operationalWindow['timezone'] }} · records at 08:00 begin the new display day</p>
            </div>

            <div class="mbfd-command-live" role="status" aria-live="polite">
                <span class="mbfd-command-live-state" data-state="live" x-show="! stale">Live</span>
                <span class="mbfd-command-live-state" data-state="stale" x-show="stale" x-cloak>Stale</span>
                <span>Updated {{ $lastUpdatedLabel }}</span>
                <span wire:loading wire:target="refreshBoard">Refreshing…</span>
            </div>

            <div class="mbfd-command-actions">
                <button type="button" wire:click="refreshBoard" wire:loading.attr="disabled" wire:target="refreshBoard">Refresh</button>
                @if($this->canExportBoard())
                    <button type="button" wire:click="exportBoard('xlsx')" wire:loading.attr="disabled" wire:target="exportBoard">Export Excel</button>
                    <button type="button" wire:click="exportBoard('pdf')" wire:loading.attr="disabled" wire:target="exportBoard">Export PDF</button>
                @endif
                @if($displayMode)
                    <a href="{{ \App\Filament\Pages\Dashboard::getUrl() }}">Exit Display Mode</a>
                @endif
            </div>
        </section>

        @php
            $queues = $department['inspectionQueues'];
            $queueGroups = [
                ['key' => 'approvals', 'eyebrow' => 'Needs a decision', 'title' => 'Inspections requiring approval', 'empty' => 'No inspections are waiting for approval.', 'tone' => 'warning'],
                ['key' => 'issues', 'eyebrow' => 'Not yet acknowledged', 'title' => 'New or escalated issues', 'empty' => 'No new or escalated equipment issues.', 'tone' => 'warning'],
                ['key' => 'actions', 'eyebrow' => 'In progress', 'title' => 'Open corrective actions', 'empty' => 'No corrective actions are open.', 'tone' => 'neutral'],
                ['key' => 'recent', 'eyebrow' => 'Last 7 days, for reference', 'title' => 'Recently completed inspections', 'empty' => 'No inspections were completed in the last 7 days.', 'tone' => 'neutral'],
            ];
        @endphp
        <section class="hub-insp-queues" aria-labelledby="mbfd-inspection-queues-heading" data-inspection-queues>
            <div class="mbfd-command-section-heading">
                <div><p class="mbfd-command-eyebrow">Daily Checkout</p><h2 id="mbfd-inspection-queues-heading">Inspections and Equipment Issues</h2></div>
                <p>Each issue is counted once, however many inspections have seen it.</p>
            </div>
            <div class="hub-insp-queues__grid">
                @foreach($queueGroups as $group)
                    @php
                        $queue = $queues[$group['key']];
                    @endphp
                    <article class="hub-insp-queue" data-queue="{{ $group['key'] }}" aria-labelledby="mbfd-queue-{{ $group['key'] }}-title">
                        <header class="hub-insp-queue__head">
                            <div>
                                <p class="hub-insp-queue__eyebrow">{{ $group['eyebrow'] }}</p>
                                <h3 class="hub-insp-queue__title" id="mbfd-queue-{{ $group['key'] }}-title">{{ $group['title'] }}</h3>
                            </div>
                            <span class="hub-insp-queue__count @if($group['tone'] === 'warning' && $queue['count'] > 0) hub-insp-queue__count--warning @endif" data-queue-count>{{ $queue['count'] }}</span>
                        </header>
                        <div class="hub-insp-queue__list" tabindex="0" aria-label="{{ $group['title'] }}">
                            @forelse($queue['rows'] as $record)
                                <a class="hub-insp-queue__row" href="{{ $record['url'] }}">
                                    <strong>{{ $record['label'] }}</strong>
                                    <small>{{ $record['status'] }}@if($record['timeLabel']) · <time datetime="{{ $record['timestamp'] }}">{{ $record['timeLabel'] }}</time>@endif</small>
                                </a>
                            @empty
                                <p class="hub-insp-queue__empty">{{ $group['empty'] }}</p>
                            @endforelse
                        </div>
                        @if($group['key'] === 'approvals' && ($queue['swapHeld'] ?? 0) > 0)
                            <p class="hub-insp-queue__foot">
                                {{ $queue['swapHeld'] }} more {{ $queue['swapHeld'] === 1 ? 'is' : 'are' }} held for a vehicle swap:
                                <a href="{{ $queues['swapUrl'] }}">Pending vehicle swaps</a>
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>

            @if($department['dailyNotes'] !== null)
                @php
                    $notes = $department['dailyNotes'];
                @endphp
                <article class="hub-insp-queue hub-insp-notefeed-card" data-queue="notes" aria-labelledby="mbfd-queue-notes-title">
                    <header class="hub-insp-queue__head">
                        <div>
                            <p class="hub-insp-queue__eyebrow">Open items first, then the last 7 days</p>
                            <h3 class="hub-insp-queue__title" id="mbfd-queue-notes-title">Daily Checkout notes &amp; messages</h3>
                        </div>
                        <span class="hub-insp-queue__count @if($notes['count'] > 0) hub-insp-queue__count--warning @endif" data-queue-count title="Notes and messages on items that are still open">{{ $notes['count'] }}</span>
                    </header>
                    <div class="hub-insp-queue__list" tabindex="0" aria-label="Daily Checkout notes and messages">
                        @forelse($notes['rows'] as $note)
                            <a @class(['hub-insp-queue__row', 'hub-insp-notefeed', 'hub-insp-notefeed--closed' => ! $note['open']]) href="{{ $note['url'] }}" data-note-kind="{{ $note['kind'] }}">
                                <span class="hub-insp-notefeed__head">
                                    <strong>{{ $note['subject'] }}</strong>
                                    <span @class(['hub-insp-notefeed__kind', 'hub-insp-notefeed__kind--internal' => $note['kind'] === 'internal'])>{{ $note['kindLabel'] }}</span>
                                    @unless($note['open'])<span class="hub-insp-notefeed__kind">Closed</span>@endunless
                                </span>
                                <span class="hub-insp-notefeed__body">{{ $note['excerpt'] }}</span>
                                <small>{{ $note['author'] }} · <time datetime="{{ $note['timestamp'] }}">{{ $note['timeLabel'] }}</time></small>
                            </a>
                        @empty
                            <p class="hub-insp-queue__empty">No internal notes or messages on Daily Checkouts.</p>
                        @endforelse
                    </div>
                </article>
            @endif
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
