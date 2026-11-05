<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <section class="rl" aria-labelledby="my-activity-all-heading">
        <div class="rl-intro">
            <h2 id="my-activity-all-heading" class="rl-title">Everything you sent or follow</h2>
            <p>The last 180 days of your inspections, equipment issues, requests and messages. Open one to see the whole record.</p>
        </div>

        <div class="rl-controls" role="search">
            <div class="rl-field rl-field--search">
                <label for="act-search">Search</label>
                <input
                    id="act-search"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Unit, item, request number or topic"
                    autocomplete="off"
                    enterkeyhint="search"
                >
            </div>
            @if(count($categories) > 1)
                <div class="rl-field rl-field--category">
                    <label for="act-category">Kind</label>
                    <select id="act-category" wire:model.live="category">
                        <option value="">Everything</option>
                        @foreach($categories as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        <p class="rl-status" role="status" aria-live="polite">
            @if($isFiltered)
                {{ $total }} {{ $total === 1 ? 'item matches' : 'items match' }}.
                <button type="button" class="rl-link-button" wire:click="clearFilters">Clear search</button>
            @else
                {{ $total }} {{ $total === 1 ? 'item' : 'items' }}.
            @endif
        </p>

        @if($items === [])
            <div class="rl-empty">
                <p class="rl-empty__title">{{ $isFiltered ? 'Nothing matches that search' : 'Nothing yet' }}</p>
                <p>{{ $isFiltered ? 'Try fewer words, or clear the search.' : 'Inspections, requests and messages you send show up here.' }}</p>
            </div>
        @else
            <div class="ep-panel act-card">
                <ul class="act-list">
                    @foreach($items as $item)
                        @include('filament.employee.partials.activity-row', ['item' => $item])
                    @endforeach
                </ul>
            </div>
        @endif

        @if($pages > 1)
            <nav class="act-pager" aria-label="Activity pages">
                <button type="button" class="rl-btn" wire:click="previousPage" @disabled($currentPage <= 1)>Previous</button>
                <span class="act-pager__position">Page {{ $currentPage }} of {{ $pages }}</span>
                <button type="button" class="rl-btn" wire:click="nextPage" @disabled($currentPage >= $pages)>Next</button>
            </nav>
        @endif
    </section>
</x-filament-panels::page>
