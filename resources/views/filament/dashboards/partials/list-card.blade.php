{{--
    A titled card holding a bounded, internally scrolling list of rows.
    Expects: $id, $title, $note, $icon, $tone, $rows (IssuePresenter rows), $empty, optional $href / $hrefLabel, $compact,
    $filterKey (the Overview filter that shows this list alone; on small screens the list stops at five rows and offers it).
--}}
<section class="mbfd-dash-card" aria-labelledby="{{ $id }}-title" data-card="{{ $id }}">
    <header class="mbfd-dash-card__head">
        <span class="mbfd-dash-card__icon" data-tone="{{ $tone }}" aria-hidden="true"><x-filament::icon :icon="$icon" class="mbfd-dash-card__svg" /></span>
        <div class="mbfd-dash-card__titles">
            <h2 id="{{ $id }}-title" class="mbfd-dash-card__title">{{ $title }}</h2>
            @if (filled($note ?? null))
                <p class="mbfd-dash-card__note">{{ $note }}</p>
            @endif
        </div>
        @if (filled($href ?? null))
            <a href="{{ $href }}" class="mbfd-dash-card__link">View all<span class="sr-only"> {{ $hrefLabel ?? $title }}</span>
                <x-filament::icon icon="heroicon-m-chevron-right" class="mbfd-dash-card__link-icon" /></a>
        @endif
    </header>
    @if (count($rows) === 0)
        <p class="mbfd-dash-empty">{{ $empty }}</p>
    @else
        <ul class="mbfd-dash-list" role="list" aria-labelledby="{{ $id }}-title">
            @foreach ($rows as $row)
                @include('filament.dashboards.partials.row', ['row' => $row, 'compact' => $compact ?? false, 'scope' => $id])
            @endforeach
        </ul>
        @if (filled($filterKey ?? null) && count($rows) > 5)
            <button type="button" class="mbfd-dash-card__more" wire:click="setFilter('{{ $filterKey }}')">See all {{ count($rows) }} in {{ $title }}</button>
        @endif
    @endif
</section>
