<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <section class="rl" aria-labelledby="rl-heading">
        <div class="rl-intro">
            <h2 id="rl-heading" class="rl-title">Resources</h2>
            <p>Department manuals and guidelines. Open a document to read it here, or download the original file.</p>
        </div>

        <div class="rl-controls" role="search">
            <div class="rl-field rl-field--search">
                <label for="rl-search">Search documents</label>
                <input
                    id="rl-search"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Title, topic, or words inside a document"
                    autocomplete="off"
                    enterkeyhint="search"
                >
            </div>
            @if(count($categories) > 1)
                <div class="rl-field rl-field--category">
                    <label for="rl-category">Category</label>
                    <select id="rl-category" wire:model.live="category">
                        <option value="">All categories</option>
                        @foreach($categories as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        <p class="rl-status" role="status" aria-live="polite">
            @if($isFiltered)
                {{ $total }} {{ $total === 1 ? 'document matches' : 'documents match' }}.
                <button type="button" class="rl-link-button" wire:click="clearFilters">Clear search</button>
            @else
                {{ $total }} {{ $total === 1 ? 'document' : 'documents' }}.
            @endif
        </p>

        @forelse($groups as $groupName => $documents)
            <section class="rl-group" aria-labelledby="rl-group-{{ $loop->index }}" wire:key="rl-group-{{ $loop->index }}">
                <div class="rl-group__header">
                    <h3 id="rl-group-{{ $loop->index }}" class="rl-group__name">{{ $groupName }}</h3>
                    <span class="rl-group__count">{{ $documents->count() }}</span>
                </div>
                <ul class="rl-list">
                    @foreach($documents as $document)
                        <li class="rl-card" wire:key="rl-doc-{{ $document->uuid }}">
                            <div class="rl-card__body">
                                <h4 class="rl-card__title">{{ $document->title }}@if($document->isNew()) <span class="rl-badge-new">New</span>@endif</h4>
                                <p class="rl-card__meta">
                                    @if($document->page_count){{ $document->page_count }} {{ $document->page_count === 1 ? 'page' : 'pages' }} &middot; @endif{{ $document->fileTypeLabel() }} &middot; {{ $document->readableSize() }}
                                </p>
                                @if($document->description)
                                    <p class="rl-card__description">{{ $document->description }}</p>
                                @endif
                            </div>
                            <div class="rl-card__actions">
                                @if($document->hasPreview())
                                    <a
                                        href="{{ route('employee.resources.preview', ['document' => $document->uuid]) }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="rl-btn rl-btn--primary"
                                    >
                                        <svg aria-hidden="true" class="rl-btn__icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
                                        <span>Open<span class="hub-visually-hidden"> {{ $document->title }} (opens in a new tab)</span></span>
                                    </a>
                                @endif
                                <a
                                    href="{{ route('employee.resources.download', ['document' => $document->uuid]) }}"
                                    class="rl-btn"
                                >
                                    <svg aria-hidden="true" class="rl-btn__icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                                    <span>Download {{ $document->fileTypeLabel() }}<span class="hub-visually-hidden">: {{ $document->title }}</span></span>
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="rl-empty">
                @if($isFiltered)
                    <h3 class="rl-empty__title">No documents match</h3>
                    <p>Try a different word, or search by a document title.</p>
                    <button type="button" class="rl-btn" wire:click="clearFilters">Clear search</button>
                @else
                    <h3 class="rl-empty__title">No documents yet</h3>
                    <p>Published manuals and guidelines will appear here.</p>
                @endif
            </div>
        @endforelse
    </section>
</x-filament-panels::page>
