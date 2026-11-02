@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\UnitMasterVehicleChange> $changes */
    /** @var bool $canDecide */
    /** @var \Closure $valueLabel */
    $sources = [
        \App\Models\UnitMasterVehicleChange::SOURCE_FY27 => 'FY27 fleet list',
        \App\Models\UnitMasterVehicleChange::SOURCE_TAKE_HOME => 'Take home inspection',
        \App\Models\UnitMasterVehicleChange::SOURCE_TAKE_HOME_NEW => 'New vehicle (take home inspection)',
    ];
@endphp
@if ($changes->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">No pending corrections.</p>
@else
    <div class="hub-insp hub-insp-table-wrap">
        <table data-hub-table class="hub-insp-table" data-proposed-corrections>
            <thead>
                <tr>
                    <th>Field</th>
                    <th>Current value</th>
                    <th>Proposed value</th>
                    <th>Source</th>
                    <th>Submitted by</th>
                    <th>Date (ET)</th>
                    @if ($canDecide)
                        <th></th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($changes as $change)
                    <tr wire:key="proposed-change-{{ $change->id }}">
                        <td>{{ $change->fieldLabel() }}</td>
                        <td>{{ $valueLabel($change, $change->old_value) }}</td>
                        <td><strong>{{ $valueLabel($change, $change->new_value) }}</strong></td>
                        <td>{{ $sources[$change->source] ?? $change->source }}@if (filled($change->source_reference)) <small>({{ $change->source_reference }})</small>@endif</td>
                        <td>{{ $change->user?->name ?? 'System' }}</td>
                        <td>{{ $change->created_at?->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A') }}</td>
                        @if ($canDecide)
                            <td>
                                <x-filament::button size="xs" color="success" wire:click="mountAction('acceptChange', @js(['change' => $change->id]))">Accept</x-filament::button>
                                <x-filament::button size="xs" color="danger" outlined wire:click="mountAction('rejectChange', @js(['change' => $change->id]))">Reject</x-filament::button>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
