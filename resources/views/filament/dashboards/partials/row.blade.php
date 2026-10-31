{{--
    One issue row. The whole row is a single <button> that opens the action panel (the contextual
    "Review / Update / View" label inside it is part of that button, so there is one tab stop per row).
    $row comes from IssuePresenter::row(); every value is escaped. $compact drops the thumbnail detail line.
--}}
@php($compact = $compact ?? false)
<li class="mbfd-dash-row" wire:key="row-{{ $scope ?? 'list' }}-{{ $row['ref'] }}">
    <button type="button" class="mbfd-dash-row__button @if ($compact) is-compact @endif"
        data-issue-ref="{{ $row['ref'] }}"
        aria-haspopup="dialog"
        x-on:click="$dispatch('dashboard-issue-open', { ref: $el.dataset.issueRef })">
        @if ($row['lane'] === 'messages' && $row['image'] === null && $row['initials'] !== null)
            <span class="mbfd-dash-thumb mbfd-dash-thumb--avatar" aria-hidden="true">{{ $row['initials'] }}</span>
        @elseif ($row['image'] !== null)
            <span class="mbfd-dash-thumb mbfd-dash-thumb--{{ $row['imageKind'] }}">
                <img src="{{ $row['image'] }}" @if ($row['image2x']) srcset="{{ $row['image'] }} 1x, {{ $row['image2x'] }} 2x" @endif
                    width="88" height="52" loading="lazy" decoding="async" alt="{{ $row['alt'] }}">
            </span>
        @else
            <span class="mbfd-dash-thumb mbfd-dash-thumb--icon" aria-hidden="true">
                <x-filament::icon :icon="$row['icon']" class="mbfd-dash-thumb__icon" />
            </span>
        @endif

        <span class="mbfd-dash-row__main">
            <span class="mbfd-dash-row__top">
                @if ($row['subject'])
                    <span class="mbfd-dash-row__subject">{{ $row['subject'] }}</span>
                @endif
                <span class="mbfd-dash-badge" data-severity="{{ $row['severity'] }}">{{ $row['severityLabel'] }}<span class="sr-only"> priority</span></span>
                @if ($row['stage'] === 'resolved')
                    <span class="mbfd-dash-badge" data-stage="resolved">{{ $row['statusLabel'] }}</span>
                @endif
            </span>
            <span class="mbfd-dash-row__title">{{ $row['title'] }}</span>
            @if (! $compact && filled($row['summary']))
                <span class="mbfd-dash-row__summary">{{ $row['summary'] }}</span>
            @endif
            @if (filled($row['actor']))
                <span class="mbfd-dash-row__byline">{{ $row['actor'] }}</span>
            @endif
        </span>

        <span class="mbfd-dash-row__meta">
            <time datetime="{{ $row['timeIso'] }}">{{ $row['time'] }}</time>
            @if (filled($row['actor']))
                <span class="mbfd-dash-row__actor" aria-hidden="true">{{ $row['actor'] }}</span>
            @endif
        </span>

        <span class="mbfd-dash-row__cta" data-stage="{{ $row['stage'] }}">{{ $row['cta'] }}</span>
    </button>
</li>
