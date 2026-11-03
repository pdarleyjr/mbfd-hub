@php
    /** @var array $unit  @var array $board  The pop-up card for one unit (contract section 10.2). */
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
    $clock = static function (?string $hhmm): string {
        if ($hhmm === null || $hhmm === '') {
            return '';
        }
        try {
            return \Illuminate\Support\Carbon::createFromFormat('H:i', $hhmm)->format('g:i A');
        } catch (\Throwable) {
            return $hhmm;
        }
    };
    $titleId = $unit['domId'] . '-full-title';
    $calls = $unit['currentCalls'] ?? [];
    $crew = $unit['crew'] ?? [];
    $profile = $unit['profile'] ?? [];
    $availability = $unit['availability'] ?? [];
    $tracking = $board['tracking'] ?? [];
    $todayCalls = $profile['todayCalls'] ?? [];
    $busiestDay = $profile['busiestDay'] ?? null;
    $verified = $etTime($availability['observedAt'] ?? null)
        ?? $etTime($board['source']['lastSourceReadAt'] ?? null)
        ?? $etTime($board['generatedAt'] ?? null);
    $short = static fn (array $p): string => trim((string) ($p['short'] ?? ''));
    $onTruck = collect($crew['onTruck'] ?? [])->map($short)->filter()->values();
    $shiftRuns = $profile['shiftRuns'] ?? ($unit['runs']['shift'] ?? 0);
    $lifetime = $profile['lifetimeRuns'] ?? ($unit['runs']['lifetime'] ?? 0);
@endphp
<div class="us-profile" data-us-profile data-unit="{{ $unit['code'] }}">
    <header class="us-profile__head">
        <h2 class="us-profile__title" id="{{ $titleId }}">{{ $unit['code'] }} <span class="us-profile__name">{{ $unit['name'] }}</span></h2>
        <p class="us-profile__meta">Station {{ $unit['station'] }}</p>
        <p class="us-profile__status">@include('unit-status.partials.chip', ['availability' => $availability])</p>
        @if(! empty($availability['note']))<p class="us-profile__meta">{{ $availability['note'] }}</p>@endif
        @if($verified)<p class="us-profile__meta">Last verified {{ $verified }}</p>@endif
    </header>

    <div class="us-blocks">
        <section class="us-block us-block--runs">
            <h3 class="us-block__heading">Runs this shift</h3>
            <p class="us-block__figure">{{ $shiftRuns }}</p>
        </section>

        @if(count($calls) > 0)
            <section class="us-block us-block--call">
                <h3 class="us-block__heading">Current call{{ count($calls) > 1 ? 's' : '' }}</h3>
                @foreach($calls as $call)
                    <div class="us-call">
                        <p class="us-call__type">{{ $call['callType'] ?: 'Call type not given' }}</p>
                        <dl class="us-facts">
                            <div><dt>ID</dt><dd>{{ $call['idLabel'] }}</dd></div>
                            @if(! empty($call['address']))<div><dt>Address</dt><dd>{{ $call['address'] }}</dd></div>@endif
                            @if(! empty($call['receivedLabel']))<div><dt>Received</dt><dd>{{ $call['receivedLabel'] }}</dd></div>@endif
                            <div><dt>Unit status</dt><dd>{{ $call['unitStatus'] ?: 'Assigned' }}</dd></div>
                        </dl>
                        @if(! empty($call['sourceUrl']) && str_starts_with((string) $call['sourceUrl'], 'https://'))
                            <a class="us-link" data-focus-key="call-{{ $call['sourceCallId'] ?? $loop->index }}" href="{{ $call['sourceUrl'] }}" target="_blank" rel="noopener noreferrer">Open in PulsePoint<span class="sr-only"> (opens in a new tab)</span></a>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif

        <section class="us-block us-block--today">
            <h3 class="us-block__heading">Calls this shift</h3>
            @if(count($todayCalls) > 0)
                <ul class="us-today">
                    @foreach($todayCalls as $run)
                        <li>
                            <span class="us-today__time">{{ $run['timeLabel'] ?? '' }}</span>
                            <span class="us-today__what">{{ $run['callType'] ?: 'Call type not given' }}</span>
                            <span class="us-today__meta">{{ $run['idLabel'] ?? '' }} · {{ ($run['unitStatus'] ?? '') ?: 'Assigned' }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="us-block__empty">No calls yet this shift</p>
            @endif
        </section>

        <section class="us-block us-block--busiest">
            <h3 class="us-block__heading">Busiest day</h3>
            @if($busiestDay)
                <p class="us-block__text"><strong>{{ $busiestDay['runs'] }} {{ (int) $busiestDay['runs'] === 1 ? 'run' : 'runs' }}</strong> · {{ $busiestDay['label'] ?? $busiestDay['date'] ?? '' }}</p>
            @else
                <p class="us-block__empty">Not enough history yet</p>
            @endif
        </section>

        <section class="us-block us-block--lifetime">
            <h3 class="us-block__heading">All-time runs</h3>
            <p class="us-block__figure">{{ $lifetime }}</p>
            @if(! empty($tracking['startsLabel']))<p class="us-block__sub">since {{ $tracking['startsLabel'] }}</p>@endif
        </section>

        <section class="us-block us-block--crew">
            <h3 class="us-block__heading">Crew today</h3>
            @if($onTruck->isNotEmpty())
                <p class="us-block__text">{{ $onTruck->implode(', ') }}</p>
            @else
                <p class="us-block__empty">{{ $crew['label'] ?? 'Personnel not confirmed' }}</p>
            @endif
            @foreach($crew['later'] ?? [] as $later)
                <p class="us-block__sub">From {{ $clock($later['from'] ?? null) }}: {{ $short($later) }}</p>
            @endforeach
            @if(($crew['vacancies'] ?? 0) > 0)<p class="us-block__sub">{{ $crew['vacancies'] }} {{ $crew['vacancies'] === 1 ? 'vacancy' : 'vacancies' }}</p>@endif
            @if(! empty($crew['note']))<p class="us-block__sub">{{ $crew['note'] }}</p>@endif
            @if($etTime($crew['asOf'] ?? null))<p class="us-block__sub">Roster as of {{ $etTime($crew['asOf']) }}</p>@endif
        </section>
    </div>

    <p class="us-profile__foot">{{ $tracking['definition'] ?? '' }} {{ $board['advisory'] ?? 'PulsePoint is advisory. Verify with dispatch.' }}</p>
</div>
