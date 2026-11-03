@php
    /** @var array $board  The view model in docs/unit-status/unit-status-contract.md sections 8 and 10.5. */
    $etTime = static function (?string $iso): ?string {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($iso)->timezone('America/New_York')->format('g:i A') . ' ET';
        } catch (\Throwable) {
            return null;
        }
    };
    $live = (bool) ($board['live'] ?? false);
    $source = $board['source'] ?? [];
    $roster = $board['roster'] ?? [];
    $updated = $etTime($board['generatedAt'] ?? null);
    $totals = $board['totals'] ?? [];
    $busiest = $totals['busiest'] ?? null;
    $busiestRuns = (int) ($busiest['runs'] ?? 0);
    try {
        $build = substr(md5(\Illuminate\Support\Facades\Vite::asset('resources/css/unit-status.css')), 0, 12);
    } catch (\Throwable) {
        $build = '';
    }

    $statusParts = [];
    if (! $live) {
        if (($source['state'] ?? 'live') !== 'live') {
            $lastRead = $etTime($source['lastSourceReadAt'] ?? null);
            $statusParts[] = trim(($source['label'] ?? 'Feed not confirmed') . ($lastRead ? ' — last update ' . $lastRead : ''));
            if (! empty($source['message'])) {
                $statusParts[] = $source['message'];
            }
        }
        if (($roster['state'] ?? 'current') !== 'current') {
            $statusParts[] = $roster['message'] ?? $roster['label'] ?? 'Roster not confirmed';
        }
        if ($statusParts === []) {
            $statusParts[] = 'Status not confirmed';
        }
    }
@endphp
<div
    class="us-board"
    data-us-board
    data-etag="{{ $board['etag'] ?? '' }}"
    data-generated-at="{{ $board['generatedAt'] ?? '' }}"
    data-build="{{ $build }}"
    data-live="{{ $live ? '1' : '0' }}"
    data-updated-label="{{ $updated }}"
>
    @if($live)
        <p class="us-subtitle">24-Hour Live Availability</p>
    @else
        <p class="us-status-line" data-us-status-line>{{ implode('. ', $statusParts) }}</p>
    @endif
    <p class="us-meta">
        @if(! empty($board['dutyWindow']['label']))<span>Duty window: {{ $board['dutyWindow']['label'] }}</span>@endif
        @if($updated)<span data-us-updated>Updated {{ $updated }}</span>@endif
    </p>

    <section class="us-totals" data-us-totals aria-label="Shift totals">
        <div class="us-total">
            <p class="us-total__label">Runs this shift</p>
            <p class="us-total__figure">{{ $totals['total'] ?? 0 }}</p>
        </div>
        <div class="us-total">
            <p class="us-total__label">Rescue</p>
            <p class="us-total__figure">{{ $totals['rescue'] ?? 0 }}</p>
        </div>
        <div class="us-total">
            <p class="us-total__label">Combat</p>
            <p class="us-total__figure">{{ $totals['combat'] ?? 0 }}</p>
        </div>
        <div class="us-total">
            <p class="us-total__label">Busiest unit</p>
            @if(! empty($busiest['codes']))
                <p class="us-total__figure us-total__figure--text">{{ implode(' · ', $busiest['codes']) }}</p>
                <p class="us-total__sub">{{ $busiestRuns }} {{ $busiestRuns === 1 ? 'run' : 'runs' }}</p>
            @else
                <p class="us-total__empty">No runs yet this shift</p>
            @endif
        </div>
    </section>
    @if(! empty($board['tracking']['note']))<p class="us-note-line">{{ $board['tracking']['note'] }}</p>@endif
    <p class="us-advisory">{{ $board['advisory'] ?? 'PulsePoint is advisory. Verify with dispatch.' }}</p>

    <div class="us-stations">
        @foreach($board['stations'] as $station)
            <section class="us-station-group" data-us-station="{{ $station['number'] }}" aria-labelledby="us-station-{{ $station['number'] }}">
                <h2 class="us-station" id="us-station-{{ $station['number'] }}">{{ $station['label'] }}</h2>
                <ul class="us-list">
                    @foreach($station['units'] as $unit)
                        @php
                            $availability = $unit['availability'];
                            $statusText = $availability['label'] ?? '';
                            $onCall = ($availability['key'] ?? '') === 'on_call';
                            $incident = $onCall ? ($unit['incidentLabel'] ?? null) : null;
                        @endphp
                        <li class="us-item" id="{{ $unit['domId'] }}" data-us-unit="{{ $unit['domId'] }}">
                            <button
                                type="button"
                                class="us-row"
                                id="{{ $unit['domId'] }}-view"
                                data-us-open="{{ $unit['domId'] }}"
                                data-unit-code="{{ $unit['code'] }}"
                                data-status="{{ $statusText }}"
                                data-summary="{{ $unit['assignmentSummary'] ?? '' }}"
                                aria-haspopup="dialog"
                            >
                                <span class="us-row__unit">
                                    <strong class="us-row__code">{{ $unit['code'] }}</strong>
                                    <span class="us-row__name">{{ $unit['name'] }}</span>
                                </span>
                                <span class="us-row__status">
                                    @include('unit-status.partials.chip', ['availability' => $availability])
                                    @if($incident)<span class="us-row__incident">{{ $incident }}</span>@endif
                                </span>
                                @include('unit-status.partials.chevron', ['class' => 'us-row__chevron'])
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    {{-- One full profile per unit; the page's single dialog is filled from the matching template. --}}
    @foreach($board['stations'] as $station)
        @foreach($station['units'] as $unit)
            <template id="profile-{{ $unit['domId'] }}" data-us-profile-template="{{ $unit['domId'] }}">
                @include('unit-status.partials.unit-profile', ['unit' => $unit, 'board' => $board, 'etTime' => $etTime])
            </template>
        @endforeach
    @endforeach
</div>
