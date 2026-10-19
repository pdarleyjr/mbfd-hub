@php
    $imageUrl = fn (string $key): string => route('employee.vehicle-inspections.image', ['inspection' => $inspectionId, 'key' => $key]);
    $statusClasses = fn (string $status): string => match ($status) {
        'Present' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'Missing' => 'bg-amber-50 text-amber-800 ring-amber-600/30',
        'Damaged' => 'bg-red-50 text-red-700 ring-red-600/20',
        default => 'bg-gray-50 text-gray-700 ring-gray-500/20',
    };
    $statusText = fn (string $status): string => $status === 'Present' ? 'Pass' : $status;
    $reviewClasses = match ($detail['review_status']) {
        'approved' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'rejected' => 'bg-red-50 text-red-700 ring-red-600/20',
        'pending_review' => 'bg-amber-50 text-amber-800 ring-amber-600/30',
        default => 'bg-gray-50 text-gray-700 ring-gray-500/20',
    };
@endphp
<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <x-filament::section>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ $detail['apparatus_label'] }}
                    @if($detail['vehicle_number'])<span class="font-normal text-gray-500">· Vehicle #{{ $detail['vehicle_number'] }}</span>@endif
                </h2>
                <p class="text-sm text-gray-600">
                    {{ $detail['reference'] }}
                    @if($detail['station_label']) · {{ $detail['station_label'] }}@endif
                </p>
            </div>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset {{ $reviewClasses }}">{{ $detail['review_label'] }}</span>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
            <div><dt class="text-gray-500">Submitted (Eastern)</dt><dd class="font-medium text-gray-900">{{ $detail['submitted_at']?->format('M j, Y g:i A') ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">Inspector</dt><dd class="font-medium text-gray-900">{{ trim($detail['inspector_rank'].' '.$detail['inspector_name']) ?: '—' }}</dd></div>
            <div><dt class="text-gray-500">Shift</dt><dd class="font-medium text-gray-900">{{ $detail['shift'] ? $detail['shift'].' Shift' : '—' }}</dd></div>
            <div><dt class="text-gray-500">Processing</dt><dd class="font-medium text-gray-900">{{ $detail['processing_label'] }}</dd></div>
            <div><dt class="text-gray-500">Engine hours</dt><dd class="font-medium text-gray-900">{{ $detail['engine_hours'] ?? 'Not reported' }}</dd></div>
            <div><dt class="text-gray-500">Miles</dt><dd class="font-medium text-gray-900">{{ $detail['miles'] !== null ? number_format($detail['miles']) : 'Not reported' }}</dd></div>
            <div><dt class="text-gray-500">Checklist version</dt><dd class="font-mono font-medium text-gray-900">{{ $detail['checklist_version_short'] ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">Review</dt>
                <dd class="font-medium text-gray-900">
                    {{ $detail['review_label'] }}
                    @if($detail['reviewed_at'])
                        <span class="block text-xs font-normal text-gray-500">Reviewed {{ $detail['reviewed_at']->format('M j, Y g:i A') }}</span>
                    @elseif($detail['review_status'] === 'approved' && $detail['automatically_accepted'])
                        <span class="block text-xs font-normal text-gray-500">Accepted automatically on submission</span>
                    @elseif($detail['review_status'] === 'pending_review')
                        <span class="block text-xs font-normal text-gray-500">Waiting for an officer to review</span>
                    @endif
                </dd>
            </div>
        </dl>

        <dl class="mt-4 grid grid-cols-3 gap-3 text-center text-sm">
            <div class="rounded-lg bg-emerald-50 p-3"><dt class="text-emerald-700">Pass</dt><dd class="text-xl font-semibold text-emerald-700">{{ $detail['totals']['present'] }}</dd></div>
            <div class="rounded-lg bg-amber-50 p-3"><dt class="text-amber-800">Missing</dt><dd class="text-xl font-semibold text-amber-800">{{ $detail['totals']['missing'] }}</dd></div>
            <div class="rounded-lg bg-red-50 p-3"><dt class="text-red-700">Damaged</dt><dd class="text-xl font-semibold text-red-700">{{ $detail['totals']['damaged'] }}</dd></div>
        </dl>
    </x-filament::section>

    <x-filament::section heading="Findings and follow-up">
        @if(count($detail['findings']) === 0 && count($detail['unmatched_defects']) === 0)
            <p class="text-sm text-gray-600">No missing or damaged items were reported on this inspection.</p>
        @else
            <p class="mb-3 text-sm text-gray-600">
                A reported defect, a service ticket and a completed repair are separate steps. Status shown is current as of now.
            </p>
            <ul class="divide-y divide-gray-200">
                @foreach(array_merge($detail['findings'], array_map(fn ($row) => $row + ['is_finding' => true, 'notes' => null, 'photo_key' => null], $detail['unmatched_defects'])) as $finding)
                    <li class="py-3">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="font-medium text-gray-900">{{ $finding['name'] }}</p>
                                <p class="text-sm text-gray-500">{{ $finding['compartment'] }}</p>
                            </div>
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusClasses($finding['status']) }}">{{ $finding['status'] }}</span>
                        </div>
                        @if(filled($finding['notes'] ?? null))
                            <p class="mt-1 whitespace-pre-wrap break-words text-sm text-gray-700">{{ $finding['notes'] }}</p>
                        @endif
                        @php($followUp = $finding['follow_up'])
                        <p class="mt-2 text-sm text-gray-800">
                            <span class="font-medium">Defect status:</span> {{ $followUp['stage'] }}
                            @if($followUp['ticket_number'])
                                · <span class="font-medium">Service ticket</span> {{ $followUp['ticket_number'] }} ({{ $followUp['ticket_status'] }})
                            @endif
                            @if($followUp['repair_completed'])
                                · <span class="font-medium text-emerald-700">Repair completed</span>
                            @endif
                        </p>
                        @if(! empty($finding['photo_key']))
                            <a href="{{ $imageUrl($finding['photo_key']) }}" target="_blank" rel="noopener" class="mt-2 inline-block">
                                <img src="{{ $imageUrl($finding['photo_key']) }}" alt="Photo of {{ $finding['name'] }}" loading="lazy" class="h-24 w-auto rounded border border-gray-200 object-cover">
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    @if(count($detail['paper_fields']) > 0)
        <x-filament::section heading="Recorded checkout fields">
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                @foreach($detail['paper_fields'] as $field)
                    <div><dt class="text-gray-500">{{ $field['name'] }}</dt><dd class="whitespace-pre-wrap break-words font-medium text-gray-900">{{ $field['value'] }}</dd></div>
                @endforeach
            </dl>
        </x-filament::section>
    @endif

    @forelse($detail['compartments'] as $compartment)
        <x-filament::section :heading="$compartment['name']" collapsible>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500">
                            <th class="px-3 py-2 font-medium">Item</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($compartment['items'] as $item)
                            <tr class="border-b border-gray-100 {{ $item['is_finding'] ? 'bg-amber-50/40' : '' }}">
                                <td class="px-3 py-2 text-gray-900">
                                    {{ $item['name'] }}
                                    @if($item['value'])<span class="block whitespace-pre-wrap break-words text-xs font-medium text-gray-600">{{ $item['value'] }}</span>@endif
                                </td>
                                <td class="px-3 py-2"><span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusClasses($item['status']) }}">{{ $statusText($item['status']) }}</span></td>
                                <td class="px-3 py-2 text-gray-600">
                                    {{ $item['notes'] ?? '—' }}
                                    @if($item['photo_key'])
                                        <a href="{{ $imageUrl($item['photo_key']) }}" target="_blank" rel="noopener" class="block text-xs text-primary-600 underline">View photo</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="text-sm text-gray-600">No detailed checklist results were recorded for this inspection.</p>
        </x-filament::section>
    @endforelse

    @if($detail['signature_key'])
        <x-filament::section heading="Inspector signature">
            <img src="{{ $imageUrl($detail['signature_key']) }}" alt="Inspector signature" loading="lazy" class="h-24 w-auto rounded border border-gray-200 bg-white">
        </x-filament::section>
    @endif
</x-filament-panels::page>
