<x-filament-panels::page data-hub-ui="2">
    @php
        $currentSnapshot = $versions->firstWhere('revision', $session->revision)?->snapshot ?? [];
        $instructorSignoff = $currentSnapshot['instructor_signoff'] ?? null;
        $leadName = $currentSnapshot['certification']['oic_snapshot']['display_name'] ?? $instructorSignoff['lead_snapshot']['display_name'] ?? $oic?->name;
    @endphp
    <x-filament::section>
        <div class="grid gap-4 sm:grid-cols-3">
            <div><p class="text-sm text-gray-500">Status</p><p class="font-semibold">{{ ucfirst($session->status) }} · revision {{ $session->revision }}</p></div>
            <div><p class="text-sm text-gray-500">Location</p><p>{{ $session->location ?: 'Not specified' }}</p></div>
            <div><p class="text-sm text-gray-500">Lead instructor / OIC</p><p>{{ $leadName ?: 'Not assigned' }}</p></div>
        </div>
        <p class="mt-4 text-sm">{{ implode(' · ', $session->classifications ?? []) }}</p>
        @if ($session->submitted_at)
            <p class="mt-4 text-sm">Submitted {{ $session->submitted_at->timezone('America/New_York')->format('M j, Y g:i:s A T') }} · approving account #{{ $session->submitted_by }}. The saved submission preserves every member's signature time.</p>
        @endif
        @if ($instructorSignoff)
            <p class="mt-2 text-sm">Lead signature: {{ $instructorSignoff['lead_snapshot']['display_name'] }} · {{ \Carbon\CarbonImmutable::parse($instructorSignoff['signed_at'])->timezone('America/New_York')->format('M j, Y g:i:s A T') }}. Recorded with confirmation by submitting account #{{ $instructorSignoff['recorded_by'] }}.</p>
        @endif
        @if (in_array($session->status, ['submitted', 'certified', 'archived'], true))
            <p class="mt-4 text-sm text-gray-600">This roster is locked. Saved submissions and certifications remain available below. Reopen with a reason before correcting attendance.</p>
        @else
            <p class="mt-4 text-sm text-gray-600">Downloads are provisional until submission or certification. Public submission requires the lead instructor's signature and an authorized administrator's approval.</p>
        @endif
    </x-filament::section>

    {{ $this->table }}

    <x-filament::section heading="Saved roster versions" description="The current roster and previous submissions and certifications. Each version preserves the attendance and signatures captured at that time; intermediate audit snapshots are also retained.">
        <div class="space-y-3">
            @forelse ($versions as $version)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3">
                    <div><strong>Revision {{ $version->revision }}</strong><span class="ml-2 text-sm">{{ $version->certified_at ? 'Certified' : (!empty($version->snapshot['submission']) ? 'Submitted' : 'Provisional') }} · {{ $version->created_at->timezone('America/New_York')->format('M j, Y g:i A') }}</span></div>
                    <div class="flex gap-3">
                        <x-filament::link :href="route('training.rosters.versions.export', ['session' => $session, 'version' => $version, 'format' => 'pdf'])">Download PDF</x-filament::link>
                        <x-filament::link :href="route('training.rosters.versions.export', ['session' => $session, 'version' => $version, 'format' => 'xlsx'])">Download Excel</x-filament::link>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">The first download or certification creates a saved roster version.</p>
            @endforelse
        </div>
    </x-filament::section>

    <x-filament::section heading="Recent audit history" description="The latest 100 changes. Complete history is retained with the roster." collapsible collapsed>
        <ol class="space-y-3">
            @forelse ($audits as $audit)
                <li class="border-b border-gray-100 pb-3 text-sm">
                    <strong>{{ str_replace('_', ' ', ucfirst($audit->action)) }}</strong>
                    <span class="text-gray-500"> · {{ $audit->created_at->timezone('America/New_York')->format('M j, Y g:i A') }}{{ $audit->actor_user_id ? ' · account #'.$audit->actor_user_id : '' }}</span>
                    @if ($audit->reason)<p class="mt-1">{{ $audit->reason }}</p>@endif
                </li>
            @empty
                <li class="text-sm text-gray-500">No recorded changes.</li>
            @endforelse
        </ol>
    </x-filament::section>
</x-filament-panels::page>
