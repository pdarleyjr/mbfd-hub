<x-filament-panels::page data-hub-ui="2">
    <x-filament::section heading="Find attendance gaps" collapsible>
        {{ $this->form }}
        <p class="mt-4 text-sm text-stone-600">Filters are included in the current-view export and page link. Published assignment context is shown as of {{ $matrix['as_of'] }}. Missing assignment data stays visible for review.</p>
    </x-filament::section>
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap gap-2" role="group" aria-label="Matrix view">
                <x-filament::button wire:click="$set('display', 'modules')" :color="$display === 'modules' ? 'primary' : 'gray'" class="min-h-11">Module summary</x-filament::button>
                <x-filament::button wire:click="$set('display', 'sessions')" :color="$display === 'sessions' ? 'primary' : 'gray'" class="min-h-11">Session detail</x-filament::button>
            </div>
            <p class="text-sm tabular-nums">{{ $rows->total() }} personnel · refreshes every 15 seconds</p>
        </div>
        <p class="mt-4 text-sm text-stone-600">Documented module coverage is attendance evidence. It does not certify proficiency or a Driver Engineer qualification. Paper evidence remains separate from electronic certification.</p>
    </x-filament::section>
    <div wire:poll.15s class="overflow-x-auto rounded-lg border border-stone-200 bg-white" role="region" aria-label="Training attendance matrix" tabindex="0">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">{{ $this->course->name }} {{ $display === 'sessions' ? 'dated session attendance' : 'module coverage' }}</caption>
            <thead class="bg-stone-100">
                <tr>
                    <th scope="col" class="sticky left-0 z-10 min-w-64 bg-stone-100 p-4">Member · current assignment</th>
                    @if ($display === 'sessions')
                        @foreach ($matrix['sessions'] as $session)
                            <th scope="col" class="min-w-48 p-4">
                                <span class="block">{{ $session['module_name'] ?: 'Unassigned' }}</span>
                                <span class="block text-xs font-normal tabular-nums">Session #{{ $session['id'] }}</span>
                                <a class="underline" href="{{ \App\Filament\Training\Resources\TrainingSessionResource::getUrl('view', ['record' => $session['id']]) }}">{{ \Carbon\CarbonImmutable::parse($session['held_on'])->format('M j, Y') }}</a>
                                <span class="block font-normal">{{ $session['starts_at'] ? \Carbon\CarbonImmutable::parse($session['starts_at'])->timezone('America/New_York')->format('g:i A T') : 'Time not recorded' }} · {{ $session['location'] ?: 'Location not recorded' }}</span>
                            </th>
                        @endforeach
                    @else
                        @foreach ($matrix['modules'] as $module)
                            <th scope="col" class="min-w-44 p-4">{{ $module['name'] }}<span class="block font-normal">{{ $module['is_required'] ? 'Required' : 'Optional' }}</span></th>
                        @endforeach
                    @endif
                    <th scope="col" class="min-w-40 p-4">Required coverage</th>
                    <th scope="col" class="min-w-48 p-4">Missing modules</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-t border-stone-200">
                        <th scope="row" class="sticky left-0 bg-white p-4 font-normal">
                            <span class="block font-semibold">{{ $row['name'] }}</span>
                            <span class="block tabular-nums">{{ $row['employee_id'] }} · {{ $row['rank'] }} · {{ $row['shift'] ?: 'Shift unverified' }}</span>
                            <span class="block text-stone-600">{{ $row['assignment'] ?: 'Assignment unverified' }}</span>
                            @if ($row['term_label'])<span class="block text-xs text-stone-600">Published bid {{ $row['term_label'] }}</span>@endif
                        </th>
                        @foreach ($display === 'sessions' ? $matrix['sessions'] : $matrix['modules'] as $column)
                            @php $cell = ($display === 'sessions' ? $row['session_cells'] : $row['module_cells'])[$column['id']] ?? ['label' => '—']; @endphp
                            <td class="p-4"><span>{{ $cell['label'] }}</span></td>
                        @endforeach
                        <td class="p-4 tabular-nums">{{ $row['covered_count'] }} / {{ $row['required_count'] }}<span class="block">{{ $row['percent'] === null ? 'Not applicable' : $row['percent'].'%' }}</span></td>
                        <td class="p-4">{{ implode(', ', $row['missing_modules']) ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($display === 'sessions' ? $matrix['sessions'] : $matrix['modules']) + 3 }}" class="p-6">No personnel match these filters. Clear a filter to broaden the view.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
    @if (count($matrix['exceptions']))
        <x-filament::section heading="Data needing review" collapsible collapsed>
            <p>{{ count($matrix['exceptions']) }} source or assignment exceptions are retained. Unverified evidence does not count as completed training. Use Paper rosters to review historical source entries.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
