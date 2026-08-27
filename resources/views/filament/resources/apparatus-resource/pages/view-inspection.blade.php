<x-filament-panels::page>
    {{-- Print-only styles --}}
    <style media="print">
        .fi-sidebar, .fi-topbar, .fi-header-actions, .fi-breadcrumbs,
        nav, [data-turbo-permanent], .fi-page-header-actions,
        button, .fi-btn { display: none !important; }
        body { background: white !important; }
        .fi-page { padding: 0 !important; }
        .print-only { display: block !important; }
    </style>

    <div class="space-y-6">
        {{-- Logo & Header --}}
        <div class="text-center print-only" style="display: block;">
            <img src="/images/mbfd_logo-256.png" alt="MBFD Logo" class="mx-auto h-20 w-20 object-contain mb-2" width="80" height="80">
            <h1 class="text-2xl font-bold text-gray-900">Miami Beach Fire Department</h1>
            <p class="text-sm text-gray-500">Daily Vehicle Inspection Report</p>
        </div>

        {{-- Inspection Summary Card --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center gap-4 mb-6">
                <img src="/images/mbfd_logo-256.png" alt="MBFD Logo" class="h-16 w-16 object-contain" width="64" height="64">
                <div>
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">
                        Inspection Report — {{ $currentDesignation }}
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Vehicle #{{ $inspection->vehicle_number ?? $apparatus->vehicle_number }}
                        &bull; {{ $inspection->completed_at?->format('F j, Y \a\t g:i A') ?? 'N/A' }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4 text-center">
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $totalItems }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Total Items</p>
                </div>
                <div class="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 p-4 text-center">
                    <p class="text-2xl font-bold text-emerald-600">{{ $presentCount }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Present</p>
                </div>
                <div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 p-4 text-center">
                    <p class="text-2xl font-bold text-amber-600">{{ $missingCount }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Missing</p>
                </div>
                <div class="rounded-lg bg-red-50 dark:bg-red-900/20 p-4 text-center">
                    <p class="text-2xl font-bold text-red-600">{{ $damagedCount }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Damaged</p>
                </div>
            </div>
        </div>

        {{-- Officer Info --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Officer Information</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Operator</span>
                    <p class="font-medium text-gray-900 dark:text-white">{{ $inspection->operator_name }}</p>
                </div>
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Rank</span>
                    <p class="font-medium text-gray-900 dark:text-white">{{ $inspection->rank }}</p>
                </div>
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Shift</span>
                    <p class="font-medium text-gray-900 dark:text-white">{{ $inspection->shift }} Shift</p>
                </div>
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Unit #</span>
                    <p class="font-medium text-gray-900 dark:text-white">{{ $inspection->unit_number ?? '—' }}</p>
                </div>
            </div>
        </div>

        {{-- Compartment Results --}}
        @if(count($results) > 0)
            @foreach($results as $compartment)
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6 dark:bg-gray-900 dark:ring-white/10">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">
                        {{ $compartment['name'] ?? 'Compartment' }}
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700">
                                    <th class="text-left py-2 px-3 text-gray-500 dark:text-gray-400 font-medium">Item</th>
                                    <th class="text-center py-2 px-3 text-gray-500 dark:text-gray-400 font-medium w-32">Status</th>
                                    <th class="text-left py-2 px-3 text-gray-500 dark:text-gray-400 font-medium">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($compartment['items'] ?? [] as $item)
                                    <tr class="border-b border-gray-100 dark:border-gray-800">
                                        <td class="py-2 px-3 text-gray-900 dark:text-white">{{ $item['name'] ?? '—' }}</td>
                                        <td class="py-2 px-3 text-center">
                                            @php $status = $item['status'] ?? 'Present'; @endphp
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                                {{ $status === 'Present' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : '' }}
                                                {{ $status === 'Missing' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' : '' }}
                                                {{ $status === 'Damaged' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : '' }}
                                            ">
                                                {{ $status === 'Present' ? '✓ Pass' : ($status === 'Missing' ? '✕ Missing' : '⚠ Damaged') }}
                                            </span>
                                        </td>
                                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400">{{ $item['notes'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @else
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6 dark:bg-gray-900 dark:ring-white/10 text-center">
                <p class="text-gray-500 dark:text-gray-400">No detailed checklist results recorded for this inspection.</p>
            </div>
        @endif

        {{-- Pending-review evidence has not changed apparatus readiness, defects, or meters. --}}
        @if($inspection->review_status === 'pending_review')
            <div class="fi-section rounded-xl bg-amber-50 shadow-sm ring-1 ring-amber-200 p-6 dark:bg-amber-900/10 dark:ring-amber-800">
                <h3 class="text-base font-semibold text-amber-900 dark:text-amber-200 mb-2">Pending officer review</h3>
                <p class="text-sm text-amber-800 dark:text-amber-300 mb-4">
                    This submission is evidence only until an authorized reviewer approves it. Approval applies the reported meter readings and defects to the apparatus.
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm mb-4">
                    <div>
                        <span class="text-amber-800 dark:text-amber-300">Reported engine hours</span>
                        <p class="font-medium text-gray-900 dark:text-white">{{ $inspection->engine_hours ?? 'Not reported' }}</p>
                    </div>
                    <div>
                        <span class="text-amber-800 dark:text-amber-300">Reported miles</span>
                        <p class="font-medium text-gray-900 dark:text-white">{{ $inspection->miles ?? 'Not reported' }}</p>
                    </div>
                </div>

                @if(count($pendingDefects) > 0)
                    <h4 class="text-sm font-semibold text-amber-900 dark:text-amber-200 mb-3">Reported pending defects ({{ count($pendingDefects) }})</h4>
                    <div class="space-y-3">
                        @foreach($pendingDefects as $defect)
                            <div class="rounded-lg bg-white dark:bg-gray-900 p-4 border border-amber-200 dark:border-amber-800">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white">{{ $defect['item'] ?? 'Unknown item' }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $defect['compartment'] ?? 'Unknown compartment' }}</p>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                        {{ $defect['status'] ?? 'Reported' }}
                                    </span>
                                </div>
                                @if(filled($defect['notes'] ?? null))
                                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $defect['notes'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-amber-800 dark:text-amber-300">No defects were reported with this pending submission.</p>
                @endif
            </div>
        @endif

        @if($reviewEvents->isNotEmpty())
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Review History</h3>
                <div class="space-y-4">
                    @foreach($reviewEvents as $reviewEvent)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p class="font-medium text-gray-900 dark:text-white">
                                    {{ ucfirst(str_replace('_', ' ', $reviewEvent->previous_status)) }} → {{ ucfirst(str_replace('_', ' ', $reviewEvent->status)) }}
                                </p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $reviewEvent->created_at?->format('M j, Y g:i A') }}</p>
                            </div>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                Reviewer: {{ $reviewEvent->changedByUser?->name ?? data_get($reviewEvent->metadata, 'reviewer_name', 'User no longer active') }}
                            </p>
                            @if(filled($reviewEvent->internal_note))
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $reviewEvent->internal_note }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Defects Section --}}
        @if($defects->count() > 0)
            <div class="fi-section rounded-xl bg-red-50 shadow-sm ring-1 ring-red-200 p-6 dark:bg-red-900/10 dark:ring-red-800">
                <h3 class="text-base font-semibold text-red-800 dark:text-red-300 mb-4">
                    ⚠ Reported Defects ({{ $defects->count() }})
                </h3>
                <div class="space-y-3">
                    @foreach($defects as $defect)
                        <div class="rounded-lg bg-white dark:bg-gray-900 p-4 border border-red-200 dark:border-red-800">
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $defect->item ?? 'Unknown Item' }}</p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $defect->compartment ?? '—' }}</p>
                                </div>
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $defect->resolved ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $defect->resolved ? 'Resolved' : 'Open' }}
                                </span>
                            </div>
                            @if($defect->notes)
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $defect->notes }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
