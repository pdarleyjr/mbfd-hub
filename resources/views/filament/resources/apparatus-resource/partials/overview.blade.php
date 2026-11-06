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
    $pm = $apparatus->getPmHealthStatus();
    $master = $apparatus->masterVehicle;
    $canOpenMaster = $master && (auth()->user()?->can('view', $master) ?? false);
    $documentCount = $canOpenMaster && \App\Support\Fleet\VehicleDocumentAccess::canViewVehicle(auth()->user(), $master)
        ? $master->documents()
            ->when(! \App\Support\Fleet\VehicleDocumentAccess::canSensitive(auth()->user()), fn ($q) => $q->where('is_sensitive', false))
            ->count()
        : null;
    $frontlineUnit = \App\Support\Fleet\FrontlineUnits::carriedBy($apparatus);
    $unitLabel = $apparatus->designation ?: $apparatus->name ?: $apparatus->unit_id;
    $vin = trim((string) $apparatus->vin);
    $vinTail = $vin === '' ? null : (mb_strlen($vin) > 6 ? 'ending '.mb_substr($vin, -6) : $vin);
    $meters = array_filter([
        $apparatus->current_miles !== null ? number_format((int) $apparatus->current_miles).' mi' : null,
        $apparatus->current_engine_hours !== null ? number_format((float) $apparatus->current_engine_hours, 1).' engine hrs' : null,
    ]);
@endphp

{{-- Layout and colour: semantic hub-insp-* classes in resources/css/filament/hub-inspection-views.css. --}}
<div class="hub-insp hub-insp-stack" data-apparatus-overview>
    <div class="hub-insp-overview-head">
        <h2>{{ $unitLabel ? $unitLabel.' on truck '.($apparatus->vehicle_number ?: 'not recorded') : 'Truck '.($apparatus->vehicle_number ?: 'not recorded') }}</h2>
        <span class="hub-insp-badge hub-insp-badge--lg hub-insp-badge--{{ $statusTone }}">{{ $status }}</span>
        @if ($frontlineUnit)
            <span class="hub-insp-badge hub-insp-badge--info" data-frontline-badge>Front-line unit</span>
        @endif
    </div>

    <div class="hub-insp-twocol" data-unit-and-truck>
        <div data-unit-card>
            <p class="hub-insp-card__title">Unit</p>
            <dl class="hub-insp-facts hub-insp-facts--plain">
                <div>
                    <dt>Unit</dt>
                    <dd>{{ $apparatus->unit_id ?: 'None (spare truck)' }}</dd>
                </div>
                <div>
                    <dt>Name</dt>
                    <dd>{{ $apparatus->name ?: ($apparatus->designation ?: 'Not set') }}</dd>
                </div>
                <div>
                    <dt>Station</dt>
                    <dd>{{ $station ? 'Station '.$station->station_number : 'Not set' }}</dd>
                </div>
                <div>
                    <dt>Class</dt>
                    <dd>{{ $apparatus->class_description ?: ($apparatus->type ?: 'Not set') }}</dd>
                </div>
            </dl>
            <p class="hub-insp-sub">
                @if ($frontlineUnit)
                    {{ $frontlineUnit }} must always have a truck In Service. When this truck goes down, use Swap truck.
                @else
                    Not a front-line unit.
                @endif
            </p>
        </div>
        <div data-truck-card>
            <p class="hub-insp-card__title">Truck on it now</p>
            <dl class="hub-insp-facts hub-insp-facts--plain">
                <div>
                    <dt>Vehicle #</dt>
                    <dd>{{ $apparatus->vehicle_number ?: 'Not recorded' }}</dd>
                </div>
                <div>
                    <dt>VIN</dt>
                    <dd>{{ $vinTail ?? 'Not recorded' }}</dd>
                </div>
                <div>
                    <dt>Truck status</dt>
                    <dd>{{ $status }}</dd>
                </div>
                <div>
                    <dt>Meters</dt>
                    <dd>{{ $meters === [] ? 'Not recorded' : implode(' · ', $meters) }}</dd>
                </div>
            </dl>
            <p class="hub-insp-sub">{{ $apparatus->assignment ?: ($apparatus->current_location ?: 'Location not recorded') }}</p>
        </div>
    </div>

    @can('viewAny', \App\Models\ApparatusChangeout::class)
        @include('filament.resources.apparatus-resource.partials.current-assignment', ['assignment' => \App\Support\Fleet\ApparatusCurrentAssignment::for($apparatus)])
    @endcan

    @if ($master)
        <div class="hub-insp-card" data-vehicle-record>
            <p><strong>Vehicle record</strong> &bull; Master Vehicle Inventory #{{ $master->veh_number }}</p>
            <p>{{ trim(implode(' ', array_filter([$master->year, $master->make, $master->model]))) ?: 'Year, make and model not recorded' }}@if ($master->serial_number) &bull; VIN {{ $master->serial_number }}@endif @if ($master->tag_number) &bull; Plate {{ $master->tag_number }}@endif</p>
            @if ($canOpenMaster)
                <p>
                    <a href="{{ \App\Filament\Resources\UnitMasterVehicleResource::getUrl('view', ['record' => $master]) }}">Open vehicle record</a>
                    @if ($documentCount !== null)
                        &bull; {{ $documentCount }} {{ $documentCount === 1 ? 'document' : 'documents' }} (accidents, repairs, registration, history)
                    @endif
                </p>
            @endif
        </div>
    @else
        <div class="hub-insp-card hub-insp-card--warning" data-vehicle-record-review>
            <p><strong>Vehicle record needs review.</strong> {{ $apparatus->master_link_note ?: 'This unit is not linked to a Master Vehicle Inventory record yet.' }}</p>
            <p>Use "Link vehicle record" under More to connect it to the right vehicle.</p>
        </div>
    @endif

    <div class="hub-insp-metrics">
        <div @class(['hub-insp-metric', 'hub-insp-metric--danger' => $overview->openDefects > 0])>
            <p class="hub-insp-metric__value" data-overview-open-defects>{{ $overview->openDefects }}</p>
            <p class="hub-insp-metric__label">Open missing / damaged items</p>
        </div>
        <div @class(['hub-insp-metric', 'hub-insp-metric--warning' => $overview->openTickets > 0])>
            <p class="hub-insp-metric__value" data-overview-open-tickets>{{ $overview->openTickets }}</p>
            <p class="hub-insp-metric__label">Open service tickets</p>
        </div>
        <div @class(['hub-insp-metric', 'hub-insp-metric--danger' => $pm['status'] === 'red', 'hub-insp-metric--warning' => $pm['status'] === 'yellow'])>
            <p class="hub-insp-metric__value" data-overview-pm>{{ $pm['hours_since_pm'] }} <small>of {{ $pm['interval_hours'] }} h</small></p>
            <p class="hub-insp-metric__label">Engine hours since PM &bull; last PM {{ $pm['last_pm_date'] ? \Illuminate\Support\Carbon::parse($pm['last_pm_date'])->format('M j, Y') : 'not recorded' }}</p>
        </div>
        <div class="hub-insp-metric">
            @if ($last)
                <p class="hub-insp-metric__value hub-insp-metric__value--text" data-overview-last-inspection>
                    <a href="{{ \App\Filament\Support\InspectionWorkspace::url($last) }}">
                        {{ $last->completed_at?->copy()->timezone(config('hub.timezone'))->format('M j, Y g:i A') ?? 'Date unavailable' }}
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
