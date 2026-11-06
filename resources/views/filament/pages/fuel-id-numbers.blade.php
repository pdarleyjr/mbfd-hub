<x-filament-panels::page>
    @php
        $source = $this->latestSource();
        $mappings = $this->mappings();
        $exceptions = $this->exceptions();
        $canManage = $this->canManage();
        $issueLabels = [
            \App\Models\FuelIdRow::UNMATCHED => ['Unmatched', 'No Hub employee has this employee ID.'],
            \App\Models\FuelIdRow::CONFLICT => ['Conflict', 'The employee ID is listed more than once, or more than one Hub record has it.'],
            \App\Models\FuelIdRow::NAME_MISMATCH => ['Name mismatch', 'The last name differs from the Hub employee with this ID.'],
        ];
        $statusLabels = ['matched' => 'Matched', 'unmatched' => 'Unmatched', 'conflict' => 'Conflict', 'name_mismatch' => 'Name mismatch'];
    @endphp

    @if ($stagedSummary)
        <x-filament::section>
            <x-slot name="heading">Check before importing</x-slot>
            <x-slot name="description">{{ $stagedName }} · {{ strtoupper((string) $stagedFormat) }} · {{ $stagedSummary['total'] }} rows. Nothing has been saved yet.</x-slot>
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ($stagedSummary['counts'] as $status => $count)
                    <div><dt class="text-sm text-gray-600 dark:text-gray-400">{{ $statusLabels[$status] ?? $status }}</dt><dd class="text-lg font-semibold tabular-nums">{{ $count }}</dd></div>
                @endforeach
            </dl>
            @if ($stagedSummary['exceptions'] !== [])
                <div class="mt-4">
                    <table data-hub-table class="w-full text-left text-sm">
                        <thead><tr class="border-b border-gray-200 dark:border-white/10"><th class="py-2 pe-4">Row</th><th class="py-2 pe-4">Employee ID</th><th class="py-2 pe-4">Name on list</th><th class="py-2 pe-4">Issue</th><th class="py-2">Fuel PIN</th></tr></thead>
                        <tbody>
                            @foreach ($stagedSummary['exceptions'] as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pe-4 tabular-nums">{{ $row['row'] }}</td>
                                    <td class="py-2 pe-4 tabular-nums">{{ $row['employee_number'] }}</td>
                                    <td class="py-2 pe-4">{{ $row['name'] }}</td>
                                    <td class="py-2 pe-4">{{ $statusLabels[$row['status']] ?? $row['status'] }}: {{ $row['reason'] }}</td>
                                    <td class="py-2 font-mono" translate="no">{{ $row['pin'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($stagedSummary['departed'] !== [])
                <p class="mt-4 text-sm">{{ count($stagedSummary['departed']) }} matched {{ \Illuminate\Support\Str::plural('employee', count($stagedSummary['departed'])) }} {{ count($stagedSummary['departed']) === 1 ? 'is' : 'are' }} marked departed and will be flagged.</p>
            @endif
            <div class="mt-4 flex flex-wrap gap-3">
                {{ $this->confirmImportAction }}
                {{ $this->cancelImportAction }}
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Source fuel list</x-slot>
        @if ($source)
            <x-slot name="description">{{ $source->original_name }} · imported {{ $source->imported_at->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A T') }} · {{ $source->row_count }} rows, {{ $source->matched_count }} matched, {{ $source->exception_count }} for review</x-slot>
            <div class="space-y-3">
                @if ($source->isPdf())
                    <iframe class="h-[70vh] w-full rounded-lg border border-gray-300 bg-gray-100 dark:border-white/10" src="{{ route('personnel.fuel-ids.sources.view', $source) }}" title="Fuel list source PDF" referrerpolicy="no-referrer"></iframe>
                @else
                    <p class="text-sm">The current list was imported from a {{ strtoupper($source->format) }} file. Download it to review the source.</p>
                @endif
                <x-filament::button tag="a" color="gray" icon="heroicon-o-arrow-down-tray" href="{{ route('personnel.fuel-ids.sources.download', $source) }}">Download</x-filament::button>
            </div>
        @else
            <p class="text-sm">No fuel list has been imported yet.</p>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Employees and Fuel PINs</x-slot>
        <x-slot name="description">PINs are hidden. Each Show is recorded.</x-slot>
        <label class="block max-w-md text-sm">Search
            <x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.350ms="search" placeholder="Name or employee ID" /></x-filament::input.wrapper>
        </label>
        <div class="mt-4">
            <table data-hub-table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="py-2 pe-4">Employee</th><th class="py-2 pe-4">Name on list</th><th class="py-2 pe-4">Department</th><th class="py-2 pe-4">Record</th><th class="py-2 pe-4">Fuel PIN</th><th class="py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mappings as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5" wire:key="fuel-map-{{ $row->id }}">
                            <td class="py-2 pe-4">{{ $row->employee?->name ?: 'Unnamed' }} <span class="tabular-nums text-gray-600 dark:text-gray-400">{{ $row->employee?->employee_id }}</span></td>
                            <td class="py-2 pe-4">{{ $row->sourceName() }} <span class="tabular-nums text-gray-600 dark:text-gray-400">{{ $row->source_employee_number }}</span></td>
                            <td class="py-2 pe-4 tabular-nums">{{ $row->department ?: 'Not listed' }}</td>
                            <td class="py-2 pe-4">
                                <x-filament::badge :color="$row->match_status === 'resolved' ? 'info' : 'success'">{{ $row->match_status === 'resolved' ? 'Resolved' : 'Matched' }}</x-filament::badge>
                                @if ($row->employee_departed)<x-filament::badge color="warning" class="mt-1">Departed</x-filament::badge>@endif
                            </td>
                            <td class="py-2 pe-4 font-mono tabular-nums" translate="no">{{ $this->isRevealed($row->id) ? $row->prompt_id : \App\Services\Personnel\FuelIds\FuelPinView::MASK }}</td>
                            <td class="py-2 text-end">
                                @if ($this->isRevealed($row->id))
                                    <x-filament::button size="sm" color="gray" wire:click="hide({{ $row->id }})">Hide</x-filament::button>
                                @else
                                    <x-filament::button size="sm" color="gray" wire:click="reveal({{ $row->id }})">Show</x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-sm">{{ $search !== '' ? 'No employees match this search.' : 'No Fuel PINs are assigned yet.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($mappings->lastPage() > 1)
            <div class="mt-4 flex items-center gap-3 text-sm">
                <x-filament::button size="sm" color="gray" :disabled="$mappings->onFirstPage()" wire:click="goToMappingPage({{ $mappings->currentPage() - 1 }})">Previous</x-filament::button>
                <span class="tabular-nums">Page {{ $mappings->currentPage() }} of {{ $mappings->lastPage() }} · {{ $mappings->total() }} employees</span>
                <x-filament::button size="sm" color="gray" :disabled="! $mappings->hasMorePages()" wire:click="goToMappingPage({{ $mappings->currentPage() + 1 }})">Next</x-filament::button>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Exceptions</x-slot>
        <x-slot name="description">These rows are not assigned to anyone until an administrator resolves them.</x-slot>
        @if ($exceptions->isEmpty())
            <p class="text-sm">No rows need review.</p>
        @else
            <div>
                <table data-hub-table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pe-4">Employee ID</th><th class="py-2 pe-4">Name on list</th><th class="py-2 pe-4">Department</th><th class="py-2 pe-4">Issue</th><th class="py-2 pe-4">Hub record</th><th class="py-2 pe-4">Fuel PIN</th><th class="py-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($exceptions as $row)
                            <tr class="border-b border-gray-100 align-top dark:border-white/5" wire:key="fuel-exception-{{ $row->id }}">
                                <td class="py-2 pe-4 tabular-nums">{{ $row->source_employee_number }}</td>
                                <td class="py-2 pe-4">{{ $row->sourceName() }}</td>
                                <td class="py-2 pe-4 tabular-nums">{{ $row->department ?: 'Not listed' }}</td>
                                <td class="py-2 pe-4"><x-filament::badge color="warning">{{ $issueLabels[$row->match_status][0] ?? $row->match_status }}</x-filament::badge><p class="mt-1 text-gray-600 dark:text-gray-400">{{ $issueLabels[$row->match_status][1] ?? '' }}</p></td>
                                <td class="py-2 pe-4">{{ $row->candidateEmployee ? $row->candidateEmployee->name.' · ID '.$row->candidateEmployee->employee_id : 'None' }}</td>
                                <td class="py-2 pe-4 font-mono tabular-nums" translate="no">{{ $this->isRevealed($row->id) ? $row->prompt_id : \App\Services\Personnel\FuelIds\FuelPinView::MASK }}</td>
                                <td class="py-2">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @if ($this->isRevealed($row->id))
                                            <x-filament::button size="sm" color="gray" wire:click="hide({{ $row->id }})">Hide</x-filament::button>
                                        @else
                                            <x-filament::button size="sm" color="gray" wire:click="reveal({{ $row->id }})">Show</x-filament::button>
                                        @endif
                                        @if ($canManage)
                                            {{ ($this->resolveAction)(['row' => $row->id]) }}
                                            {{ ($this->dismissAction)(['row' => $row->id]) }}
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
