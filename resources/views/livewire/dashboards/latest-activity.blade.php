{{--
    Latest activity (Admin Overview). Newest first. Every row is a link to the record it is about; nothing here changes anything.
    The 30 s poll (wire:poll.30s.visible="tick") is skipped while focus is inside the list ($wire.paused is set by the
    two handlers below), so a row never jumps while someone is reading or tabbing through it.
--}}
@php
    $kinds = ['all' => 'All', 'needs_action' => 'Needs action', 'messages' => 'Messages'];
    $empty = match ($kind) {
        'needs_action' => 'Nothing needs action right now.',
        'messages' => 'No messages in the last 14 days.',
        default => 'No activity in the last 14 days.',
    };
@endphp
<section class="mbfd-dash-card mbfd-dash-activity" aria-labelledby="mbfd-activity-title" data-card="latest-activity"
    wire:poll.30s.visible="tick">
    <header class="mbfd-dash-card__head">
        <span class="mbfd-dash-card__icon" data-tone="info" aria-hidden="true"><x-filament::icon icon="heroicon-o-clock" class="mbfd-dash-card__svg" /></span>
        <div class="mbfd-dash-card__titles">
            <h2 id="mbfd-activity-title" class="mbfd-dash-card__title">Latest activity</h2>
            <p class="mbfd-dash-card__note">Newest first, last 14 days · updated <time datetime="{{ $updatedAt->toIso8601String() }}">{{ $updatedAt->timezone(\App\Support\OperationalDisplayWindow::TIMEZONE)->format('g:i A') }}</time></p>
        </div>
        <div class="mbfd-dash-activity__kinds" role="group" aria-label="Show activity">
            @foreach ($kinds as $key => $label)
                <button type="button" class="mbfd-dash-chip mbfd-dash-activity__kind-button" wire:key="activity-kind-{{ $key }}"
                    wire:click="setKind('{{ $key }}')" aria-pressed="{{ $kind === $key ? 'true' : 'false' }}">
                    <span>{{ $label }}</span>
                    @if ($key === 'needs_action' && $needsAction > 0)
                        <span class="mbfd-dash-chip__count">· {{ number_format($needsAction) }}</span>
                    @endif
                </button>
            @endforeach
        </div>
        @if (filled($historyUrl))
            <a href="{{ $historyUrl }}" class="mbfd-dash-card__link">View all activity
                <x-filament::icon icon="heroicon-m-chevron-right" class="mbfd-dash-card__link-icon" /></a>
        @endif
    </header>

    @if (count($rows) === 0)
        <p class="mbfd-dash-empty">{{ $empty }}</p>
    @else
        <ul class="mbfd-dash-list mbfd-dash-activity__list" role="list" aria-label="Latest activity" data-activity-list
            x-data
            x-on:focusin="$wire.paused = true"
            x-on:focusout="if (! $el.contains($event.relatedTarget)) $wire.paused = false">
            @foreach ($rows as $row)
                @include('livewire.dashboards.partials.latest-activity-row', ['event' => $row['event'], 'time' => $row['time'], 'timeIso' => $row['timeIso']])
            @endforeach
        </ul>
    @endif
</section>
