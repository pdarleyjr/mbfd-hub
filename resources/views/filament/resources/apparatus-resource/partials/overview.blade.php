@php
    /** @var \App\Models\Apparatus $apparatus */
    /** @var \App\Support\Fleet\ApparatusOverview $overview */
    $status = (string) ($apparatus->status ?? 'Unknown');
    $statusColor = match ($status) {
        'In Service', 'Available' => 'success',
        'Out of Service' => 'danger',
        'Maintenance' => 'warning',
        default => 'gray',
    };
    $station = $apparatus->station;
    $last = $overview->lastInspection;
@endphp

<div class="space-y-4" data-apparatus-overview>
    <div class="flex flex-wrap items-center gap-3">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
            {{ $apparatus->designation ?: $apparatus->name ?: 'Apparatus' }}
        </h2>
        <x-filament::badge :color="$statusColor">{{ $status }}</x-filament::badge>
    </div>

    <dl class="grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Vehicle #</dt>
            <dd class="font-medium text-gray-900 dark:text-white">{{ $apparatus->vehicle_number ?: '—' }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Class</dt>
            <dd class="font-medium text-gray-900 dark:text-white">{{ $apparatus->class_description ?: ($apparatus->type ?: '—') }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Station</dt>
            <dd class="font-medium text-gray-900 dark:text-white">{{ $station ? 'Station '.$station->station_number : '—' }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Assignment</dt>
            <dd class="font-medium text-gray-900 dark:text-white">{{ $apparatus->assignment ?: ($apparatus->current_location ?: '—') }}</dd>
        </div>
    </dl>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="rounded-lg p-4 {{ $overview->openDefects > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-gray-50 dark:bg-gray-800' }}">
            <p class="text-2xl font-bold text-gray-900 dark:text-white" data-overview-open-defects>{{ $overview->openDefects }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">Open missing / damaged items</p>
        </div>
        <div class="rounded-lg p-4 {{ $overview->openTickets > 0 ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-gray-50 dark:bg-gray-800' }}">
            <p class="text-2xl font-bold text-gray-900 dark:text-white" data-overview-open-tickets>{{ $overview->openTickets }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">Open service tickets</p>
        </div>
        <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
            @if ($last)
                <p class="text-base font-semibold text-gray-900 dark:text-white" data-overview-last-inspection>
                    <a class="underline" href="{{ route('filament.admin.resources.apparatuses.view-inspection', ['record' => $apparatus->getKey(), 'inspection' => $last->getKey()]) }}">
                        {{ $last->completed_at?->format('M j, Y g:i A') ?? 'Date unavailable' }}
                    </a>
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Last inspection &bull; {{ $last->displayStatus() }}</p>
            @else
                <p class="text-base font-semibold text-gray-900 dark:text-white" data-overview-last-inspection>None recorded</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Last inspection</p>
            @endif
        </div>
    </div>
</div>
