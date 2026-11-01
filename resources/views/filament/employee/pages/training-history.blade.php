<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <x-filament::section>
        <p class="text-sm text-gray-600">Your valid training attendance, including archived classes. Voided entries are excluded. Recorded rank and shift reflect the original attendance, and corrections are identified. Provisional rosters remain subject to officer certification. Contact the Training Division to request a correction or an official record.</p>
    </x-filament::section>
    {{ $this->table }}
    <x-filament::section heading="Verified paper attendance">
        <p class="mb-4 text-sm text-stone-600">Your reviewed historical paper entries, dated when the class occurred. These are paper attendance records, not electronic certifications. Contact Training to request a correction or source review.</p>
        <div>
            <table data-hub-table class="w-full text-left text-sm">
                <thead><tr><th scope="col" class="p-3">Course</th><th scope="col" class="p-3">Module</th><th scope="col" class="p-3">Class date</th><th scope="col" class="p-3">Evidence</th></tr></thead>
                <tbody>
                    @forelse ($paperAttendance as $entry)
                        <tr class="border-t border-stone-200"><td class="p-3">{{ $entry->session->course->name }}</td><td class="p-3">{{ $entry->session->module?->name ?: 'Unassigned' }}</td><td class="p-3">{{ $entry->session->held_on->format('M j, Y') }}</td><td class="p-3">Verified paper roster</td></tr>
                    @empty
                        <tr><td colspan="4" class="p-3">No verified paper attendance recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
