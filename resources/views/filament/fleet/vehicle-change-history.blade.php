@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\UnitMasterVehicleChange> $changes */
    /** @var \Closure $valueLabel */
    $sources = [
        \App\Models\UnitMasterVehicleChange::SOURCE_FY27 => 'FY27 fleet list',
        \App\Models\UnitMasterVehicleChange::SOURCE_TAKE_HOME => 'Take home inspection',
        \App\Models\UnitMasterVehicleChange::SOURCE_TAKE_HOME_NEW => 'New vehicle (take home inspection)',
        \App\Models\UnitMasterVehicleChange::SOURCE_APPARATUS_LINK => 'Linked to apparatus',
        \App\Models\UnitMasterVehicleChange::SOURCE_APPARATUS_PAGE => 'Apparatus page',
        \App\Models\UnitMasterVehicleChange::SOURCE_MASTER_INVENTORY => 'Master Vehicle Inventory',
        \App\Models\UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT => 'Daily Checkout',
    ];
    $statusColors = ['applied' => 'info', 'accepted' => 'success', 'rejected' => 'gray'];
@endphp
@if ($changes->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">No changes recorded.</p>
@else
    <div class="hub-insp hub-insp-table-wrap">
        <table class="hub-insp-table" data-change-history>
            <thead>
                <tr>
                    <th>When (ET)</th>
                    <th>Field</th>
                    <th>Change</th>
                    <th>Status</th>
                    <th>Source</th>
                    <th>Who</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($changes as $change)
                    <tr>
                        <td>{{ ($change->decided_at ?? $change->created_at)?->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A') }}</td>
                        <td>{{ $change->fieldLabel() }}</td>
                        <td>
                            @if ($change->field === 'record')
                                Record {{ $change->new_value }}
                            @else
                                {{ $valueLabel($change, $change->old_value) }} &rarr; {{ $valueLabel($change, $change->new_value) }}
                            @endif
                            @if ($change->note)
                                <br><small>{{ $change->note }}</small>
                            @endif
                        </td>
                        <td><x-filament::badge :color="$statusColors[$change->status] ?? 'gray'">{{ ucfirst($change->status) }}</x-filament::badge></td>
                        <td>{{ $sources[$change->source] ?? $change->source }}</td>
                        <td>
                            {{ $change->user?->name ?? 'System' }}
                            @if ($change->decidedBy)
                                <small>decided by {{ $change->decidedBy->name }}</small>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
