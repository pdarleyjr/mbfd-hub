{{--
    Activity history: the Overview's Latest activity, 30 days deep. Read-only. Rows are the same partial the card uses.
    $this->paginator() is one page (50) of the newest events this person is allowed to see.
--}}
@php
    $page = $this->paginator();
@endphp
<x-filament-panels::page>
    <div class="mbfd-dash mbfd-dash-history">
        <p class="mbfd-dash-history__intro">
            Equipment issues, station requests, personnel requests and Support Services messages from the last 30 days, newest first.
            Open a line to go to the record.
        </p>

        <div class="mbfd-dash-history__controls">
            <label class="mbfd-dash-history__field mbfd-dash-history__field--search">
                <span>Search</span>
                <input type="search" class="mbfd-dash-history__input" wire:model.live.debounce.400ms="search"
                    placeholder="Unit, station, person or words" autocomplete="off">
            </label>
            <label class="mbfd-dash-history__field">
                <span>Area</span>
                <select class="mbfd-dash-history__input" wire:model.live="area">
                    <option value="all">All areas</option>
                    <option value="apparatus">Apparatus</option>
                    <option value="stations">Stations</option>
                    <option value="personnel">Personnel</option>
                </select>
            </label>
            <label class="mbfd-dash-history__field">
                <span>Show</span>
                <select class="mbfd-dash-history__input" wire:model.live="kind">
                    <option value="all">Everything</option>
                    <option value="needs_action">Needs action</option>
                    <option value="messages">Messages</option>
                </select>
            </label>
            <label class="mbfd-dash-history__field">
                <span>Order</span>
                <select class="mbfd-dash-history__input" wire:model.live="sort">
                    @foreach (\App\Services\Activity\OperationsActivityPreferences::ACTIVITY_OPTIONS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <section class="mbfd-dash-card mbfd-dash-activity" aria-labelledby="mbfd-history-title">
            <header class="mbfd-dash-card__head">
                <span class="mbfd-dash-card__icon" data-tone="info" aria-hidden="true"><x-filament::icon icon="heroicon-o-clock" class="mbfd-dash-card__svg" /></span>
                <div class="mbfd-dash-card__titles">
                    <h2 id="mbfd-history-title" class="mbfd-dash-card__title">Activity</h2>
                    <p class="mbfd-dash-card__note">{{ number_format($page->total()) }} {{ \Illuminate\Support\Str::plural('event', $page->total()) }}</p>
                </div>
            </header>
            @if ($page->total() === 0)
                <p class="mbfd-dash-empty">Nothing matches. Clear the search or choose another area.</p>
            @else
                <ul class="mbfd-dash-list mbfd-dash-activity__list" role="list" aria-label="Activity history">
                    @foreach ($page->items() as $row)
                        @include('livewire.dashboards.partials.latest-activity-row', ['event' => $row['event'], 'time' => $row['time'], 'timeIso' => $row['timeIso']])
                    @endforeach
                </ul>
            @endif
        </section>

        <x-filament::pagination :paginator="$page" />
    </div>
</x-filament-panels::page>
