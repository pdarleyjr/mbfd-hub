<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <section class="rl" aria-labelledby="my-documents-heading" data-my-documents>
        <div class="rl-intro">
            <h2 id="my-documents-heading" class="rl-title">My Documents</h2>
            <p>Files shared with you, your submitted reports and completed forms, and your taskbooks. Only you and authorized administrators can open them.</p>
        </div>

        @if ($total > 0)
            <div class="rl-filters" style="display:flex;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem">
                <label class="hub-visually-hidden" for="my-documents-search">Search documents</label>
                <input id="my-documents-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search by name or type"
                    class="fi-input block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-white/10 dark:bg-white/5" style="max-width:22rem;min-height:44px">
                <label class="hub-visually-hidden" for="my-documents-type">Type</label>
                <select id="my-documents-type" wire:model.live="type"
                    class="fi-select-input block rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-white/10 dark:bg-white/5" style="min-height:44px">
                    <option value="">All types</option>
                    @foreach ($types as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <ul class="rl-list">
            @forelse ($items as $item)
                <li class="rl-card" wire:key="doc-{{ $item['key'] }}">
                    <div class="rl-card__body">
                        <h3 class="rl-card__title">{{ $item['title'] }}</h3>
                        <p class="rl-card__meta">{{ $item['type'] }} &middot; {{ $item['date_label'] ?? 'Date not recorded' }} &middot; {{ $item['meta'] }}@if ($item['status']) &middot; {{ $item['status'] }}@endif</p>
                        @if (filled($item['related']))
                            <p class="rl-card__description">{{ $item['related'] }}</p>
                        @endif
                    </div>
                    <div class="rl-card__actions">
                        <a href="{{ $item['preview'] }}" target="_blank" rel="noopener"
                            @if ($item['pdf']) data-hub-viewer="pdf" data-hub-viewer-title="{{ $item['title'] }}" data-hub-viewer-download="{{ $item['download'] }}" @endif
                            class="rl-btn rl-btn--primary">
                            <span>{{ $item['pdf'] ? 'Preview' : 'Open' }}<span class="hub-visually-hidden"> {{ $item['title'] }}</span></span>
                        </a>
                        <a href="{{ $item['download'] }}" class="rl-btn">
                            <span>Download<span class="hub-visually-hidden">: {{ $item['title'] }}</span></span>
                        </a>
                    </div>
                </li>
            @empty
                <li class="rl-empty">
                    @if ($total === 0)
                        <h3 class="rl-empty__title">No documents yet</h3>
                        <p>Documents an administrator shares with you, reports you submit and forms you complete will appear here.</p>
                    @else
                        <h3 class="rl-empty__title">No matching documents</h3>
                        <p>Try a different search or type.</p>
                    @endif
                </li>
            @endforelse
        </ul>
    </section>
</x-filament-panels::page>
