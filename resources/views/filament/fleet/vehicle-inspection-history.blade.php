@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\TakeHomeVehicleInspection> $inspections */
@endphp
@if ($inspections->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">No inspections on record.</p>
@else
    <div class="hub-insp hub-insp-table-wrap">
        <table class="hub-insp-table" data-inspection-history>
            <thead>
                <tr>
                    <th>Submitted (ET)</th>
                    <th>Reference</th>
                    <th>Inspector</th>
                    <th>Driver</th>
                    <th>Odometer</th>
                    <th>Acceptable</th>
                    <th class="is-center">Issues</th>
                    <th class="is-center">Not checked</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($inspections as $inspection)
                    @php
                        $checks = collect($inspection->checks ?? []);
                    @endphp
                    <tr class="{{ $inspection->needsAttention() ? 'is-finding' : '' }}">
                        <td>{{ $inspection->submittedAtLocal() }}</td>
                        <td>{{ $inspection->reference }}</td>
                        <td>{{ $inspection->inspector_name }}</td>
                        <td>{{ $inspection->driver_name }}</td>
                        <td>
                            @if ($inspection->odometer !== null)
                                {{ number_format($inspection->odometer) }}
                            @else
                                {{ \App\Models\TakeHomeVehicleInspection::ODOMETER_EXCEPTIONS[$inspection->odometer_exception] ?? 'Not recorded' }}
                            @endif
                        </td>
                        <td>
                            <x-filament::badge :color="$inspection->acceptable_for_use ? 'success' : 'danger'">
                                {{ $inspection->acceptable_for_use ? 'Yes' : 'No' }}
                            </x-filament::badge>
                        </td>
                        <td class="is-center">{{ $checks->where('status', \App\Models\TakeHomeVehicleInspection::STATUS_ISSUE)->count() }}</td>
                        <td class="is-center">{{ $checks->where('status', \App\Models\TakeHomeVehicleInspection::STATUS_NOT_CHECKED)->count() }}</td>
                        <td>
                            <x-filament::link :href="route('take-home-inspections.show', $inspection->reference)" size="sm">View</x-filament::link>
                            <x-filament::link :href="route('take-home-inspections.pdf', $inspection->reference)" size="sm">PDF</x-filament::link>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
