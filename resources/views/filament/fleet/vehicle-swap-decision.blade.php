@php
    /** @var \App\Models\ApparatusVehicleSwapRequest $swap */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Apparatus> $otherUnits */
@endphp
<div class="hub-insp hub-insp-stack hub-swap" data-vehicle-swap-summary>
    <dl class="hub-insp-facts">
        <div>
            <dt>Unit</dt>
            <dd>{{ $swap->unit_designation ?: 'Unit' }}</dd>
        </div>
        <div>
            <dt>Assigned vehicle</dt>
            <dd class="hub-swap-id">{{ $swap->assigned_vehicle_number ?: 'None recorded' }}</dd>
        </div>
        <div>
            <dt>Reported vehicle</dt>
            <dd class="hub-swap-id">{{ $swap->reported_vehicle_number }}
                @unless ($swap->reported_vehicle_listed)
                    <small>Typed in. Not on the fleet list when reported.</small>
                @endunless
            </dd>
        </div>
        <div>
            <dt>Reported by</dt>
            <dd>{{ $swap->reporter_name }}<small>{{ $swap->created_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') }}</small></dd>
        </div>
    </dl>

    <dl class="hub-insp-fields">
        <div>
            <dt>Was a truck swap completed?</dt>
            <dd>{{ \App\Models\ApparatusVehicleSwapRequest::answerLabel($swap->answer) }}</dd>
        </div>
        <div>
            <dt>Note from the member</dt>
            <dd>{{ $swap->note ?: 'No note' }}</dd>
        </div>
        <div>
            <dt>Other units carrying vehicle {{ $swap->reported_vehicle_number }}</dt>
            @php
                $otherUnitsLabel = $otherUnits->map(function ($other): string {
                    $status = $other->getAttribute('status');
                    $role = \App\Support\Fleet\FleetVehicleCatalog::roleLabel(\App\Support\Fleet\FleetVehicleCatalog::roleFor($status));

                    return ($other->designation ?: $other->name).' ('.$role.($status ? ', '.$status : '').')';
                })->implode('; ');
            @endphp
            {{-- One line on purpose: this definition list preserves white space. --}}
            <dd>{{ $otherUnitsLabel !== '' ? $otherUnitsLabel : 'None. It is not assigned to any unit.' }}</dd>
        </div>
    </dl>
</div>
