@php
    /** @var array $unit  @var array $board  @var string $variant 'inline'|'full' */
    $variant = $variant ?? 'inline';
    $full = $variant === 'full';
    $etTime ??= static function (?string $iso): ?string {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($iso)->timezone('America/New_York')->format('g:i A') . ' ET';
        } catch (\Throwable) {
            return null;
        }
    };
    $titleId = $unit['domId'] . '-' . $variant . '-title';
    $calls = $unit['currentCalls'] ?? [];
    $shownCalls = $full ? $calls : array_slice($calls, 0, 1);
    $crew = $unit['crew'] ?? [];
    $runs = $unit['runs'] ?? [];
    $availability = $unit['availability'] ?? [];
    $tracking = $board['tracking'] ?? [];
    $names = collect($crew['onTruck'] ?? [])->map(fn ($p) => trim(($p['rank'] ?? '') . ' ' . ($p['name'] ?? '')))->filter()->values();
    $lastSeen = ! empty($availability['stale']) ? $etTime($availability['observedAt'] ?? null) : null;
@endphp
<div class="us-profile us-profile--{{ $variant }}" data-us-profile data-unit="{{ $unit['code'] }}">
    <div class="us-cards">
        <section class="us-card us-card--identity" aria-labelledby="{{ $titleId }}">
            <h2 class="us-card__title" id="{{ $titleId }}">{{ $unit['code'] }} <span class="us-card__name">{{ $unit['name'] }}</span></h2>
            <p class="us-card__meta">Station {{ $unit['station'] }}</p>
            <p class="us-card__status">@include('unit-status.partials.chip', ['availability' => $availability])</p>
            @if(! empty($availability['note']))<p class="us-card__meta">{{ $availability['note'] }}</p>@endif
            @if($lastSeen)<p class="us-card__meta">Last seen {{ $lastSeen }}</p>@endif
            @unless($full)<p class="us-card__hint">Clickable unit profile</p>@endunless
        </section>

        <section class="us-card us-card--call">
            <h3 class="us-card__heading">Current Call{{ count($calls) > 1 && $full ? 's' : '' }}</h3>
            @forelse($shownCalls as $call)
                <div class="us-call">
                    <p class="us-call__type">{{ $call['callType'] ?: 'Call type not given' }}</p>
                    <dl class="us-facts">
                        <div><dt>ID</dt><dd>{{ $call['idLabel'] }}</dd></div>
                        @if(! empty($call['receivedLabel']))<div><dt>Received</dt><dd>{{ $call['receivedLabel'] }}</dd></div>@endif
                        @if(! empty($call['address']))<div><dt>Address</dt><dd>{{ $call['address'] }}</dd></div>@endif
                        <div><dt>Unit status</dt><dd>{{ $call['unitStatus'] ?: 'Assigned' }}@if(($call['unitConfirmed'] ?? null) === false) (unconfirmed)@endif</dd></div>
                        @if($full && ! empty($call['lastSeenLabel']))<div><dt>Last seen</dt><dd>{{ $call['lastSeenLabel'] }}</dd></div>@endif
                        @if($full && ! empty($call['location']))<div><dt>Location</dt><dd>Approximate location</dd></div>@endif
                    </dl>
                    @if($full && ! empty($call['otherUnits']))
                        <p class="us-call__others">Also listed: {{ collect($call['otherUnits'])->map(fn ($o) => trim(($o['designation'] ?? '') . ' ' . ($o['status'] ?? '')))->implode(', ') }}</p>
                    @endif
                    @if(! empty($call['sourceUrl']) && str_starts_with((string) $call['sourceUrl'], 'https://'))
                        <a class="us-link" data-focus-key="call-{{ $call['sourceCallId'] ?? $loop->index }}" href="{{ $call['sourceUrl'] }}" target="_blank" rel="noopener noreferrer">Open in PulsePoint<span class="sr-only"> (opens in a new tab)</span></a>
                    @endif
                </div>
            @empty
                <p class="us-card__empty">{{ $unit['assignmentSummary'] ?? 'No active call' }}</p>
            @endforelse
            @if(! $full && count($calls) > 1)<p class="us-card__meta">+{{ count($calls) - 1 }} more {{ count($calls) - 1 === 1 ? 'call' : 'calls' }}</p>@endif
        </section>

        <section class="us-card us-card--runs">
            <h3 class="us-card__heading">24h Runs</h3>
            <p class="us-card__figure">{{ $runs['shift'] ?? 0 }}</p>
            <p class="us-card__meta">This duty shift, {{ $board['dutyWindow']['label'] ?? '' }}</p>
            @if(($runs['shiftUnconfirmed'] ?? 0) > 0)<p class="us-card__meta">+{{ $runs['shiftUnconfirmed'] }} unconfirmed</p>@endif
        </section>

        <section class="us-card us-card--lifetime">
            <h3 class="us-card__heading">Lifetime Runs</h3>
            <p class="us-card__figure">{{ $runs['lifetime'] ?? 0 }}</p>
            <p class="us-card__meta">Since System Go-Live{{ ! empty($tracking['startsLabel']) ? ' (' . $tracking['startsLabel'] . ')' : '' }}</p>
            @if(($runs['lifetimeUnconfirmed'] ?? 0) > 0)<p class="us-card__meta">+{{ $runs['lifetimeUnconfirmed'] }} unconfirmed</p>@endif
            @if($full && ($runs['backfilledLifetime'] ?? 0) > 0)<p class="us-card__meta">{{ $runs['backfilledLifetime'] }} recovered from the PulsePoint record after the fact.</p>@endif
        </section>

        <section class="us-card us-card--crew">
            <h3 class="us-card__heading">Personnel (This Shift)</h3>
            @if($names->isNotEmpty())
                <ul class="us-crew">
                    @foreach($names as $line)<li>{{ $line }}</li>@endforeach
                </ul>
            @else
                <p class="us-card__empty">{{ $crew['label'] ?? 'Personnel not confirmed' }}</p>
            @endif
            @if(($crew['vacancies'] ?? 0) > 0)<p class="us-card__meta">{{ $crew['vacancies'] }} {{ $crew['vacancies'] === 1 ? 'vacancy' : 'vacancies' }}</p>@endif
            @if($full)
                @if(! empty($crew['later']))
                    <p class="us-card__meta">Later this shift</p>
                    <ul class="us-crew us-crew--later">
                        @foreach($crew['later'] as $p)<li>{{ trim(($p['rank'] ?? '') . ' ' . ($p['name'] ?? '')) }} <span class="us-card__meta">{{ $p['from'] ?? '' }} to {{ $p['through'] ?? '' }}</span></li>@endforeach
                    </ul>
                @endif
                @if(! empty($crew['note']))<p class="us-card__meta">{{ $crew['note'] }}</p>@endif
                @if($etTime($crew['asOf'] ?? null))<p class="us-card__meta">Roster as of {{ $etTime($crew['asOf']) }}</p>@endif
            @endif
        </section>
    </div>

    @if($full)
        <div class="us-profile__more">
            <section class="us-section">
                <h3 class="us-card__heading">How runs are counted</h3>
                <p class="us-section__text">{{ $tracking['definition'] ?? '' }}</p>
            </section>
            @if(! empty($tracking['gaps']))
                <section class="us-section">
                    <h3 class="us-card__heading">Coverage gaps</h3>
                    <ul class="us-gaps">
                        @foreach($tracking['gaps'] as $gap)<li>{{ $gap['label'] ?? '' }}</li>@endforeach
                    </ul>
                </section>
            @endif
            <section class="us-section" data-us-runs data-url="{{ url('/unit-status/units/' . rawurlencode($unit['code']) . '/runs') }}" data-page="0">
                <h3 class="us-card__heading">Recent runs</h3>
                <p class="us-section__text" data-us-runs-status role="status">Loading recent runs</p>
                <ul class="us-runs-list" data-us-runs-list></ul>
                <button type="button" class="hub-btn hub-btn--secondary" data-us-runs-more data-focus-key="runs-more" hidden>Show more</button>
            </section>
        </div>
    @endif
</div>
