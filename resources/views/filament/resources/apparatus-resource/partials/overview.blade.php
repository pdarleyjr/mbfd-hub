@php
    /** @var \App\Models\Apparatus $apparatus */
    /** @var \App\Support\Fleet\ApparatusOverview $overview */
    $status = (string) ($apparatus->status ?? 'Unknown');
    $statusTone = match ($status) {
        'In Service', 'Available' => 'success',
        'Out of Service' => 'danger',
        'Maintenance' => 'warning',
        default => 'neutral',
    };
    $station = $apparatus->station;
    $last = $overview->lastInspection;
@endphp

{{-- Layout and colour: semantic hub-insp-* classes in resources/css/filament/hub-inspection-views.css. --}}
<div class="hub-insp hub-insp-stack" data-apparatus-overview>
    <div class="hub-insp-overview-head">
        <h2>{{ $apparatus->designation ?: $apparatus->name ?: 'Apparatus' }}</h2>
        <span class="hub-insp-badge hub-insp-badge--lg hub-insp-badge--{{ $statusTone }}">{{ $status }}</span>
    </div>

    <dl class="hub-insp-facts hub-insp-facts--plain">
        <div>
            <dt>Vehicle #</dt>
            <dd>{{ $apparatus->vehicle_number ?: '—' }}</dd>
        </div>
        <div>
            <dt>Class</dt>
            <dd>{{ $apparatus->class_description ?: ($apparatus->type ?: '—') }}</dd>
        </div>
        <div>
            <dt>Station</dt>
            <dd>{{ $station ? 'Station '.$station->station_number : '—' }}</dd>
        </div>
        <div>
            <dt>Assignment</dt>
            <dd>{{ $apparatus->assignment ?: ($apparatus->current_location ?: '—') }}</dd>
        </div>
    </dl>

    <div class="hub-insp-metrics">
        <div @class(['hub-insp-metric', 'hub-insp-metric--danger' => $overview->openDefects > 0])>
            <p class="hub-insp-metric__value" data-overview-open-defects>{{ $overview->openDefects }}</p>
            <p class="hub-insp-metric__label">Open missing / damaged items</p>
        </div>
        <div @class(['hub-insp-metric', 'hub-insp-metric--warning' => $overview->openTickets > 0])>
            <p class="hub-insp-metric__value" data-overview-open-tickets>{{ $overview->openTickets }}</p>
            <p class="hub-insp-metric__label">Open service tickets</p>
        </div>
        <div class="hub-insp-metric">
            @if ($last)
                <p class="hub-insp-metric__value hub-insp-metric__value--text" data-overview-last-inspection>
                    <a href="{{ route('filament.admin.resources.apparatuses.view-inspection', ['record' => $apparatus->getKey(), 'inspection' => $last->getKey()]) }}">
                        {{ $last->completed_at?->format('M j, Y g:i A') ?? 'Date unavailable' }}
                    </a>
                </p>
                <p class="hub-insp-metric__label">Last inspection &bull; {{ $last->displayStatus() }}</p>
            @else
                <p class="hub-insp-metric__value hub-insp-metric__value--text" data-overview-last-inspection>None recorded</p>
                <p class="hub-insp-metric__label">Last inspection</p>
            @endif
        </div>
    </div>
</div>
