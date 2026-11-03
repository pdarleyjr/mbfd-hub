@php
    /** @var array $board  The view model in docs/unit-status/unit-status-contract.md section 8. */
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
    data-live="{{ $live ? '1' : '0' }}"
    data-updated-label="{{ $updated }}"
>
    @if($live)
        <p class="us-subtitle">24-Hour Live Availability</p>
    @else
        <p class="us-status-line" data-us-status-line>{{ implode('. ', $statusParts) }}</p>
    @endif
    <p class="us-advisory">{{ $board['advisory'] ?? 'PulsePoint is advisory. Verify with dispatch.' }}</p>
    <p class="us-meta">
        @if(! empty($board['dutyWindow']['label']))<span>Duty window: {{ $board['dutyWindow']['label'] }}</span>@endif
        @if($updated)<span data-us-updated>Updated {{ $updated }}</span>@endif
    </p>

    <div class="us-table-wrap">
        <table class="us-table">
            <caption class="sr-only">Unit status for every apparatus, grouped by station</caption>
            <thead>
                <tr>
                    <th scope="col" class="us-col--unit">Unit</th>
                    <th scope="col" class="us-col--status">Availability / Status</th>
                    <th scope="col" class="us-col--assign">Current Assignment / Call Status</th>
                    <th scope="col" class="us-col--runs">24h Runs <span class="sr-only">this duty shift since 8:00 AM</span></th>
                    <th scope="col" class="us-col--crew">Personnel on Truck (This Shift)</th>
                    <th scope="col" class="us-col--profile">Profile / Details</th>
                </tr>
            </thead>
            @foreach($board['stations'] as $station)
                <tbody class="us-station-group" data-us-station="{{ $station['number'] }}">
                    <tr class="us-station">
                        <th scope="rowgroup" colspan="6">{{ $station['label'] }}</th>
                    </tr>
                    @foreach($station['units'] as $unit)
                        @php
                            $availability = $unit['availability'];
                            $crew = $unit['crew'] ?? [];
                            $calls = $unit['currentCalls'] ?? [];
                            $names = collect($crew['onTruck'] ?? [])->map(fn ($p) => trim(($p['rank'] ?? '') . ' ' . ($p['name'] ?? '')))->filter()->values();
                            $lastSeen = ! empty($availability['stale']) ? $etTime($availability['observedAt'] ?? null) : null;
                            $more = max(0, count($calls) - 1);
                            $statusText = ($availability['label'] ?? '') . (($availability['confirmed'] ?? true) ? '' : ' (unconfirmed)');
                        @endphp
                        <tr
                            class="us-unit"
                            id="{{ $unit['domId'] }}"
                            data-us-unit="{{ $unit['domId'] }}"
                            data-unit-code="{{ $unit['code'] }}"
                            data-status="{{ $statusText }}"
                            data-summary="{{ $unit['assignmentSummary'] ?? '' }}"
                        >
                            <th scope="row" class="us-cell us-cell--unit">
                                <button
                                    type="button"
                                    class="us-toggle"
                                    id="{{ $unit['domId'] }}-toggle"
                                    data-us-toggle="{{ $unit['domId'] }}"
                                    aria-expanded="false"
                                    aria-controls="{{ $unit['domId'] }}-panel"
                                >
                                    @include('unit-status.partials.chevron')
                                    <span class="us-toggle__text">
                                        <strong class="us-toggle__code">{{ $unit['code'] }}</strong>
                                        <span class="us-toggle__name">{{ $unit['name'] }}</span>
                                    </span>
                                </button>
                            </th>
                            <td class="us-cell us-cell--status">
                                <span class="us-cell__label">Availability / Status</span>
                                @include('unit-status.partials.chip', ['availability' => $availability])
                                @if(! empty($availability['note']))<span class="us-note">{{ $availability['note'] }}</span>@endif
                                @if($lastSeen)<span class="us-note">Last seen {{ $lastSeen }}</span>@endif
                            </td>
                            <td class="us-cell us-cell--assign">
                                <span class="us-cell__label">Current Assignment / Call Status</span>
                                <span class="us-assign">{{ $unit['assignmentSummary'] ?? 'Not confirmed' }}</span>
                                @if(! empty($calls[0]))
                                    <span class="us-note">{{ $calls[0]['idLabel'] }}@if(! empty($calls[0]['receivedLabel'])) · Received {{ $calls[0]['receivedLabel'] }}@endif</span>
                                @endif
                                @if(! empty($unit['conflict']) && $more > 0)<span class="us-note us-note--more">+{{ $more }} more {{ $more === 1 ? 'call' : 'calls' }}</span>@endif
                            </td>
                            <td class="us-cell us-cell--runs">
                                <span class="us-cell__label">24h Runs</span>
                                <span class="us-runs">{{ $unit['runs']['shift'] ?? 0 }}<span class="sr-only"> runs this duty shift</span></span>
                                @if(($unit['runs']['shiftUnconfirmed'] ?? 0) > 0)<span class="us-note">+{{ $unit['runs']['shiftUnconfirmed'] }} unconfirmed</span>@endif
                            </td>
                            <td class="us-cell us-cell--crew">
                                <span class="us-cell__label">Personnel on Truck (This Shift)</span>
                                @if($names->isNotEmpty())
                                    <span class="us-crew-line">{{ $names->implode(', ') }}</span>
                                    @if(($crew['state'] ?? '') === 'delayed')<span class="us-note">{{ $crew['label'] ?? 'Roster delayed' }}</span>@endif
                                @else
                                    <span class="us-crew-line us-crew-line--muted">{{ $crew['label'] ?? 'Personnel not confirmed' }}</span>
                                @endif
                                @if(($crew['vacancies'] ?? 0) > 0)<span class="us-note">{{ $crew['vacancies'] }} {{ $crew['vacancies'] === 1 ? 'vacancy' : 'vacancies' }}</span>@endif
                            </td>
                            <td class="us-cell us-cell--profile">
                                <button type="button" class="us-view" id="{{ $unit['domId'] }}-view" data-us-open="{{ $unit['domId'] }}">
                                    <span>View profile<span class="sr-only"> for {{ $unit['name'] }}</span></span>
                                    @include('unit-status.partials.chevron', ['class' => 'us-view__icon'])
                                </button>
                            </td>
                        </tr>
                        <tr class="us-panel-row" id="{{ $unit['domId'] }}-panel" data-us-panel="{{ $unit['domId'] }}" hidden>
                            <td colspan="6" class="us-panel-cell">
                                @include('unit-status.partials.unit-profile', ['unit' => $unit, 'board' => $board, 'variant' => 'inline', 'etTime' => $etTime])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            @endforeach
        </table>
    </div>

    {{-- One full profile per unit; the page's single dialog is filled from the matching template. --}}
    @foreach($board['stations'] as $station)
        @foreach($station['units'] as $unit)
            <template id="profile-{{ $unit['domId'] }}" data-us-profile-template="{{ $unit['domId'] }}">
                @include('unit-status.partials.unit-profile', ['unit' => $unit, 'board' => $board, 'variant' => 'full', 'etTime' => $etTime])
            </template>
        @endforeach
    @endforeach
</div>
