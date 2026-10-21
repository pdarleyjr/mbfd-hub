@php
    /** @var \App\Models\TakeHomeVehicleInspection|null $inspection */
    $checkLabels = \App\Models\TakeHomeVehicleInspection::CHECKS;
    $checks = collect($inspection?->checks ?? []);
    $labelFor = fn (array $check): string => $checkLabels[$check['key'] ?? ''][0] ?? (string) ($check['key'] ?? 'Check');
    $issues = $checks->where('status', \App\Models\TakeHomeVehicleInspection::STATUS_ISSUE)->values();
    $notChecked = $checks->where('status', \App\Models\TakeHomeVehicleInspection::STATUS_NOT_CHECKED)->values();
@endphp
@if (! $inspection)
    <p class="text-sm text-gray-500 dark:text-gray-400">No take-home inspection has been submitted for this vehicle.</p>
@else
    <div class="hub-insp hub-insp-stack">
        <div class="hub-insp-card {{ $inspection->acceptable_for_use ? '' : 'hub-insp-card--danger' }}" data-last-take-home-inspection>
            <div class="hub-insp-head">
                <div class="hub-insp-head__main">
                    <p class="hub-insp-title">{{ $inspection->submittedAtLocal() }} <small>{{ $inspection->reference }}</small></p>
                </div>
                <x-filament::badge :color="$inspection->acceptable_for_use ? 'success' : 'danger'" size="lg">
                    {{ $inspection->acceptable_for_use ? 'Acceptable for use: Yes' : 'Acceptable for use: No' }}
                </x-filament::badge>
            </div>

            <dl class="hub-insp-facts">
                <div>
                    <dt>Inspector</dt>
                    <dd>{{ $inspection->inspector_name }}</dd>
                </div>
                <div>
                    <dt>Driver</dt>
                    <dd>{{ $inspection->driver_name }}
                        @if ($inspection->driver_verified && filled($inspection->driver_employee_number))
                            <small>Employee ID {{ $inspection->driver_employee_number }}</small>
                        @else
                            <small>Driver not verified against the roster</small>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt>Issues found</dt>
                    <dd>
                        @forelse ($issues as $check)
                            <span>{{ $labelFor($check) }}@if (filled($check['issue_description'] ?? null)): {{ $check['issue_description'] }}@endif</span><br>
                        @empty
                            None
                        @endforelse
                    </dd>
                </div>
                <div>
                    <dt>Not checked</dt>
                    <dd>
                        @forelse ($notChecked as $check)
                            <span>{{ $labelFor($check) }}</span><br>
                        @empty
                            None
                        @endforelse
                    </dd>
                </div>
            </dl>

            <div class="mt-4 flex flex-wrap gap-3">
                <x-filament::button tag="a" :href="route('take-home-inspections.show', $inspection->reference)" icon="heroicon-o-eye" size="sm">
                    View report
                </x-filament::button>
                <x-filament::button tag="a" :href="route('take-home-inspections.pdf', $inspection->reference)" icon="heroicon-o-arrow-down-tray" size="sm" color="gray">
                    Download PDF
                </x-filament::button>
            </div>
        </div>
    </div>
@endif
