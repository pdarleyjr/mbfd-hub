@php
    use App\Support\HubTimezone;
    $tz = HubTimezone::NAME;
    $fmt = fn ($moment) => $moment ? $moment->copy()->setTimezone($tz)->format('M j, Y g:i A') : null;
    $document = $report->document;
    $vehicle = $report->vehicle;
    $vehicleUrl = $vehicle && \App\Filament\Resources\UnitMasterVehicleResource::canView($vehicle)
        ? \App\Filament\Resources\UnitMasterVehicleResource::getUrl('view', ['record' => $vehicle]) : null;
    $actions = [
        'started' => 'Report started', 'submitted' => 'Submitted', 'supervisor_completed' => 'Supervisor\'s part completed',
        'acknowledged' => 'Alert acknowledged', 'follow_up_required' => 'Follow-up required', 'follow_up_cleared' => 'Follow-up cleared',
        'resolved' => 'Resolved', 'reopened' => 'Reopened', 'amended' => 'Entry corrected', 'pdf_generated' => 'Official PDF produced',
    ];
@endphp
<x-filament-panels::page>
    <div class="space-y-6" data-accident-report="{{ $report->reference }}">
        @if ($report->resolved_at === null && $report->alert_acknowledged_at === null)
            <div role="alert" class="rounded-xl border border-danger-300 bg-danger-50 px-4 py-3 text-danger-800 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-200">
                <p class="text-sm font-semibold uppercase tracking-wide">Accident reported. Action required.</p>
                <p class="mt-1 text-sm">Submitted {{ $fmt($report->submitted_at) }}. Acknowledge it to show administration has seen it; resolve it when it has been addressed.</p>
            </div>
        @endif

        <x-filament::section>
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3 text-sm">
                <div><dt class="text-gray-500 dark:text-gray-400">Status</dt><dd class="font-medium">{{ $report->statusLabel() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Employee involved</dt><dd class="font-medium">{{ $report->subject?->name ?? 'Not recorded' }}@if($report->subject?->employee_id) <span class="text-gray-500">(ID {{ $report->subject->employee_id }})</span>@endif</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Reported by</dt><dd class="font-medium">{{ $report->reporter?->name ?? 'Not recorded' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Supervisor</dt><dd class="font-medium">{{ $report->supervisor ? trim(($report->supervisor->rank ? $report->supervisor->rank.' ' : '').$report->supervisor->name) : 'Not assigned' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Vehicle</dt><dd class="font-medium">
                    @if ($vehicle)
                        @if ($vehicleUrl)<a href="{{ $vehicleUrl }}" class="text-primary-600 underline-offset-2 hover:underline dark:text-primary-400">@endif
                        Vehicle {{ $vehicle->veh_number }}{{ $vehicle->apparatus?->designation ? ' · '.$vehicle->apparatus->designation : '' }}
                        @if ($vehicleUrl)</a>@endif
                    @else
                        Not a vehicle report
                    @endif
                </dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Station / assignment</dt><dd class="font-medium">{{ $report->station_label ?? 'Not recorded' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Accident date and time</dt><dd class="font-medium">{{ $fmt($report->occurred_at) ?? 'Not recorded' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Submitted</dt><dd class="font-medium">{{ $fmt($report->submitted_at) }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Acknowledged</dt><dd class="font-medium">{{ $report->alert_acknowledged_at ? $fmt($report->alert_acknowledged_at).' by '.($report->acknowledgedBy?->name ?? 'administrator') : 'Not yet' }}</dd></div>
                @if ($report->follow_up_required)
                    <div class="sm:col-span-2 lg:col-span-3"><dt class="text-gray-500 dark:text-gray-400">Follow-up required</dt><dd class="font-medium">{{ $report->follow_up_note }}</dd></div>
                @endif
                @if ($report->resolved_at)
                    <div class="sm:col-span-2 lg:col-span-3"><dt class="text-gray-500 dark:text-gray-400">Resolution</dt><dd class="font-medium">{{ $report->resolution_note }} <span class="text-gray-500">({{ $fmt($report->resolved_at) }})</span></dd></div>
                @endif
            </dl>
            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                The City form requires two copies to go to Risk Management immediately (305-673-7014, fax 305-673-7023). Submitting in the Hub does not deliver them; download the official PDF below for delivery.
            </p>
        </x-filament::section>

        <x-filament::section heading="Official PDF" collapsible>
            @if ($document)
                <div class="mb-3 flex flex-wrap items-center gap-3 text-sm">
                    <x-filament::button tag="a" href="{{ route('personnel-documents.download', $document) }}" icon="heroicon-o-arrow-down-tray" size="sm">Download PDF</x-filament::button>
                    <x-filament::button tag="a" href="{{ route('personnel-documents.view', $document) }}" data-hub-viewer="pdf" data-hub-viewer-title="{{ $report->typeLabel() }} {{ $report->reference }}" data-hub-viewer-download="{{ route('personnel-documents.download', $document) }}" color="gray" size="sm">Open full screen</x-filament::button>
                    <span class="text-gray-500">Version {{ $document->version }} · produced {{ $fmt($document->created_at) }}</span>
                </div>
                <iframe src="{{ route('personnel-documents.view', $document) }}" title="{{ $report->typeLabel() }} {{ $report->reference }}" class="h-[75vh] w-full rounded-lg border border-gray-200 dark:border-white/10" loading="lazy"></iframe>
            @elseif ($report->completed_at)
                <p class="text-sm text-gray-600 dark:text-gray-300">The official PDF is being produced. Refresh in a moment. If it does not appear, use More, then Produce PDF again.</p>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-300">The official PDF is produced when the supervisor completes their part.</p>
            @endif
        </x-filament::section>

        @foreach ($sections as $heading => $rows)
            <x-filament::section :heading="$heading" collapsible>
                @if ($rows === [])
                    <p class="text-sm text-gray-600 dark:text-gray-300">Awaiting the supervisor{{ $report->supervisor ? ' ('.$report->supervisor->name.')' : '' }}.</p>
                @else
                    <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        @foreach ($rows as $row)
                            <div @class(['sm:col-span-2' => $row['long']])>
                                <dt class="text-gray-500 dark:text-gray-400">{{ $row['label'] }}</dt>
                                <dd @class(['whitespace-pre-line font-medium', 'text-gray-400 font-normal' => $row['value'] === 'Not entered'])>{{ $row['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </x-filament::section>
        @endforeach

        <x-filament::section heading="History" collapsible collapsed>
            <ol class="space-y-2 text-sm">
                @foreach ($report->events->sortBy('created_at') as $event)
                    <li class="flex flex-wrap gap-x-2">
                        <span class="text-gray-500">{{ $fmt($event->created_at) }}</span>
                        <span class="font-medium">{{ $actions[$event->action] ?? ucfirst(str_replace('_', ' ', $event->action)) }}</span>
                        <span class="text-gray-600 dark:text-gray-300">{{ $event->actor?->name ? 'by '.$event->actor->name : '' }}</span>
                        @if ($event->action === 'amended')
                            <span class="w-full text-gray-600 dark:text-gray-300">Reason: {{ $event->metadata['reason'] ?? '' }}. Changed: {{ implode(', ', array_map(fn ($k) => \App\Services\Accidents\AccidentReportSchema::LABELS[$k] ?? $k, array_keys($event->metadata['after'] ?? []))) }}.</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </x-filament::section>
    </div>
</x-filament-panels::page>
