@php
    $account = \App\Filament\Support\EmployeeAccessSchema::account($getRecord());
    $registry = app(\App\Support\ApplicationAccessRegistry::class);
    $states = $account ? $registry->states($account) : [];
@endphp
<div class="space-y-4">
    <p class="text-sm text-gray-500">Entry permission and administrator roles are separate. Saved grants do not prove that a remote integration is deployed or healthy.</p>
    @if ($account)
        <div class="overflow-x-auto">
            <table class="w-full min-w-[48rem] text-left text-sm">
                <thead><tr class="border-b"><th class="p-3">Application</th><th class="p-3">Access</th><th class="p-3">Role</th><th class="p-3">Enforcement</th></tr></thead>
                <tbody>
                    @foreach ($registry->applications() as $key => $application)
                        <tr class="border-b align-top">
                            <th class="p-3 font-medium">{{ $application['label'] }}</th>
                            <td class="p-3">{{ $states[$key]['grant_status'] }}<p class="mt-1 text-xs text-gray-500">{{ $states[$key]['status'] }}</p></td>
                            <td class="p-3">{{ $states[$key]['role'] ?? ($key === 'admin' ? 'Hub capabilities' : (in_array($key, ['cmd', 'cloud']) ? 'App-managed' : 'Not assigned')) }}<p class="mt-1 text-xs text-gray-500">{{ $states[$key]['role_status'] }}</p></td>
                            <td class="p-3">{{ $states[$key]['runtime_status'] }}@if ($key === 'cloud')<p class="mt-2 text-xs">{{ $registry->cloudEnforcementStatus($account) }}</p>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p>No login account exists yet. Create or link the verified account before granting application access.</p>
    @endif
</div>
