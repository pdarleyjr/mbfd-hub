@php
    $record = $getRecord();
    $account = \App\Filament\Support\EmployeeAccessSchema::account($record);
    $employee = $record instanceof \App\Models\Employee ? $record : $account?->employeeProfile;
    $memberships = $account ? \App\Models\WorkgroupMember::query()->with('workgroup')->where('user_id', $account->id)->orderBy('workgroup_id')->get() : collect();
    $events = $account ? \App\Models\SecurityActionEvent::query()->with('actor')->where('target_user_id', $account->id)->latest('id')->limit(50)->get() : collect();
    $profileEvents = $employee ? \App\Models\EmployeeProfileEvent::query()->where('employee_id', $employee->id)->latest('id')->limit(50)->get() : collect();
@endphp
<div class="space-y-6">
    <section>
        <h3 class="text-base font-semibold">Workgroups</h3>
        <ul class="mt-2 space-y-2">
            @forelse ($memberships as $membership)
                <li>{{ $membership->workgroup?->name ?? 'Retained workgroup record' }} — {{ $membership->role }}</li>
            @empty
                <li class="text-sm text-gray-500">No workgroup memberships.</li>
            @endforelse
        </ul>
        <p class="mt-2 text-sm text-gray-500">Workgroup membership and workgroup roles are independent of Hub administration and application access.</p>
    </section>
    @if ($employee)
        <section>
            <h3 class="text-base font-semibold">Retained personnel records</h3>
            <p class="mt-2 text-sm">Assigned equipment: {{ $employee->assignedEquipment()->count() }} · Equipment requests: {{ $employee->equipmentRequests()->count() }} · Personnel requests: {{ $employee->personnelRequests()->count() }} · Operational forms: {{ $employee->operationalFormRecords()->count() }}</p>
            <p class="mt-2 text-sm text-gray-500">Disabling access preserves these records and the existing Employee ID and database links.</p>
        </section>
    @endif
    <section>
        <h3 class="text-base font-semibold">Personnel and identity audit — latest 50 events</h3>
        <ul class="mt-2 space-y-2 text-sm">
            @forelse ($profileEvents as $event)
                <li>{{ $event->created_at->format('M j, Y g:i A') }} — {{ $event->action }} / {{ $event->result }} · Administrator account {{ $event->actor_user_id }} · {{ $event->reason ?? 'Profile fields updated' }}</li>
            @empty
                <li class="text-gray-500">No recorded personnel changes.</li>
            @endforelse
        </ul>
    </section>
    <section>
        <h3 class="text-base font-semibold">Security audit — latest 50 events</h3>
        <div class="overflow-x-auto">
            <table class="mt-2 w-full text-left text-sm">
                <thead><tr><th class="p-2">Time</th><th class="p-2">Action / result</th><th class="p-2">Administrator</th><th class="p-2">Reason</th></tr></thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr><td class="p-2">{{ $event->created_at->format('M j, Y g:i A') }}</td><td class="p-2">{{ $event->action }} / {{ $event->result }}</td><td class="p-2">{{ $event->actor?->name ?? 'Retained actor ID '.$event->actor_user_id }}</td><td class="p-2">{{ $event->reason ?? '—' }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="p-2 text-gray-500">No recorded security actions.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
