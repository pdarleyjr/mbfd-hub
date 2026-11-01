<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <section class="rl" aria-labelledby="taskbooks-heading">
        <div class="rl-intro">
            <h2 id="taskbooks-heading" class="rl-title">Taskbooks</h2>
            <p>Your assigned taskbooks. Preview a PDF in your browser or download a copy.</p>
        </div>
        <ul class="rl-list">
            @forelse($documents as $document)
                <li class="rl-card" wire:key="taskbook-{{ $document->uuid }}">
                    <div class="rl-card__body">
                        <h3 class="rl-card__title">{{ $document->title }}@if($document->isNew()) <span class="rl-badge-new">New</span>@endif</h3>
                        <p class="rl-card__meta">{{ $document->page_count }} pages &middot; PDF &middot; {{ $document->readableSize() }}</p>
                        @if($document->description)
                            <p class="rl-card__description">{{ $document->description }}</p>
                        @endif
                    </div>
                    <div class="rl-card__actions">
                        <a href="{{ route('employee.taskbooks.preview', ['document' => $document->uuid]) }}" target="_blank" rel="noopener" data-hub-viewer="pdf" data-hub-viewer-title="{{ $document->title }}" data-hub-viewer-download="{{ route('employee.taskbooks.download', ['document' => $document->uuid]) }}" class="rl-btn rl-btn--primary">
                            <span>Preview<span class="hub-visually-hidden"> {{ $document->title }}</span></span>
                        </a>
                        <a href="{{ route('employee.taskbooks.download', ['document' => $document->uuid]) }}" class="rl-btn">
                            <span>Download PDF<span class="hub-visually-hidden">: {{ $document->title }}</span></span>
                        </a>
                    </div>
                </li>
            @empty
                <li class="rl-empty">
                    <h3 class="rl-empty__title">No taskbooks yet</h3>
                    <p>Taskbooks assigned to you will appear here.</p>
                </li>
            @endforelse
        </ul>
    </section>
</x-filament-panels::page>
