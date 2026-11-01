<x-filament-panels::page data-hub-ui="2">
    <x-filament::section heading="Original paper evidence">
        <p class="text-sm">Approved entries count as verified paper attendance. Uncertain names, dates, unsigned lines and duplicates stay in review. The original scan is retained unchanged; reviewing a member does not certify the roster electronically.</p>
        <ul class="mt-4 space-y-3">
            @forelse ($imports as $import)
                <li><x-filament::link :href="route('training.paper.source', ['import' => $import])" target="_blank" data-hub-viewer="pdf" data-hub-viewer-title="Original paper, {{ $import->session->held_on->format('M j, Y') }}">{{ $import->session->held_on->format('M j, Y') }} · {{ $import->page_count }} pages · Original PDF</x-filament::link></li>
            @empty
                <li>No historical sources have been imported for this course.</li>
            @endforelse
        </ul>
    </x-filament::section>
    {{ $this->table }}
</x-filament-panels::page>
