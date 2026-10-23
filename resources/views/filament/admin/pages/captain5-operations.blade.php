{{-- Filament sections and actions only; layout utilities, no colour literals. --}}
<x-filament-panels::page>
    <div class="mx-auto w-full max-w-5xl space-y-6" data-testid="captain5-operations">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Control who can use Captain 5, approve acting Captain 5 requests, and decide which Engine and Ladder items count as medical.
            Every change is saved with your reason and stays in the history.
        </p>

        {{-- Who has access --}}
        <x-filament::section heading="Who has access" description="Look up a member to see why they can or cannot use Captain 5.">
            <div class="space-y-4">
                <div>
                    <label for="captain5-member-search" class="sr-only">Search for a member</label>
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                        <x-filament::input id="captain5-member-search" type="search" wire:model.live.debounce.400ms="memberSearch" placeholder="Search by name or employee ID" autocomplete="off" class="min-h-[44px]" />
                    </x-filament::input.wrapper>
                </div>

                @if ($lookup['results'] !== [])
                    <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10" aria-label="Matching members">
                        @foreach ($lookup['results'] as $result)
                            <li wire:key="captain5-result-{{ $result['id'] }}">
                                <button type="button" wire:click="chooseMember({{ $result['id'] }})" class="flex min-h-[44px] w-full items-center px-4 py-2 text-left text-sm hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 dark:hover:bg-white/5">
                                    {{ $result['label'] }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($lookup['searching'])
                    <p class="text-sm text-gray-600 dark:text-gray-400">No current member matches that search.</p>
                @endif

                @if ($lookup['member'] !== null)
                    @php $explained = $lookup['explained']; @endphp
                    <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-white/10" data-testid="captain5-lookup-result">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $lookup['member']['name'] }}</h3>
                            <div class="flex items-center gap-2">
                                {{ ($this->changeAccessAction)(['employee' => $lookup['member']['id']]) }}
                                <x-filament::button color="gray" size="sm" outlined wire:click="clearMember">Close</x-filament::button>
                            </div>
                        </div>

                        <p class="flex items-center gap-2 text-sm font-medium text-gray-950 dark:text-white">
                            <x-filament::badge :color="$explained['tone']">{{ $explained['tone'] === 'success' ? 'Access' : ($explained['tone'] === 'danger' ? 'Denied' : 'No access') }}</x-filament::badge>
                            <span>{{ $explained['headline'] }}</span>
                        </p>

                        @if ($explained['sources'] !== [])
                            <div>
                                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Why</h4>
                                <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-gray-700 dark:text-gray-300">
                                    @foreach ($explained['sources'] as $source)
                                        <li>{{ $source }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($explained['override'] !== null)
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                <span class="font-semibold text-gray-950 dark:text-white">Administrator choice: {{ $explained['override']['label'] }}.</span>
                                {{ $explained['override']['reason'] }}
                                <span class="text-gray-600 dark:text-gray-400">Set by {{ $explained['override']['set_by'] }}, {{ $explained['override']['at'] }}.</span>
                            </p>
                        @else
                            <p class="text-sm text-gray-600 dark:text-gray-400">No administrator choice is set. Rank, seat and administrator access decide.</p>
                        @endif

                        @foreach ($explained['notes'] as $note)
                            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $note }}</p>
                        @endforeach

                        @if ($explained['capabilities'] !== [])
                            <ul class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2" aria-label="What this member can do">
                                @foreach ($explained['capabilities'] as $capability)
                                    <li class="flex items-center gap-2">
                                        <x-filament::icon :icon="$capability['allowed'] ? 'heroicon-m-check-circle' : 'heroicon-m-minus-circle'" class="h-5 w-5 {{ $capability['allowed'] ? 'text-success-600' : 'text-gray-400' }}" />
                                        <span>{{ $capability['label'] }}: <span class="font-medium">{{ $capability['allowed'] ? 'Yes' : 'No' }}</span></span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- Access overrides --}}
        <x-filament::section heading="Access choices" description="Allow or deny one member. Everyone else follows rank, seat and administrator access.">
            <x-slot name="headerEnd">{{ $this->setAccessAction }}</x-slot>

            @if ($overrides === [])
                <p class="text-sm text-gray-600 dark:text-gray-400">No access choices are set. Use Set Captain 5 access to allow or deny a member.</p>
            @else
                <ul class="divide-y divide-gray-200 dark:divide-white/10" data-testid="captain5-overrides">
                    @foreach ($overrides as $row)
                        <li class="flex flex-wrap items-start justify-between gap-3 py-3" wire:key="captain5-override-{{ $row['employee_id'] }}">
                            <div class="min-w-0 space-y-1">
                                <p class="text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ $row['member'] }}
                                    @if ($row['rank'] !== '')<span class="font-normal text-gray-600 dark:text-gray-400">· {{ $row['rank'] }}</span>@endif
                                </p>
                                <p class="text-sm text-gray-700 dark:text-gray-300">{{ $row['reason'] }}</p>
                                <p class="text-xs text-gray-600 dark:text-gray-400">Set by {{ $row['set_by'] }}, {{ $row['at'] }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-filament::badge :color="$row['choice'] === 'deny' ? 'danger' : 'success'">{{ $row['label'] }}</x-filament::badge>
                                {{ ($this->changeAccessAction)(['employee' => $row['employee_id']]) }}
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-3">
                <x-filament::button color="gray" size="sm" outlined wire:click="toggleOverrideHistory">
                    {{ $this->showOverrideHistory ? 'Hide history' : 'Show history' }}
                </x-filament::button>
            </div>
            @if ($this->showOverrideHistory)
                <div class="mt-3 space-y-2" data-testid="captain5-override-history">
                    @forelse ($overrideHistory as $entry)
                        <p class="text-sm text-gray-700 dark:text-gray-300">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $entry['member'] }}: {{ $entry['label'] }}.</span>
                            {{ $entry['reason'] }}
                            <span class="text-gray-600 dark:text-gray-400">Set by {{ $entry['set_by'] }}, {{ $entry['at'] }}. {{ $entry['ended'] }}.</span>
                        </p>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-400">No access choices have been recorded yet.</p>
                    @endforelse
                </div>
            @endif
        </x-filament::section>

        {{-- Acting Captain 5 requests --}}
        <x-filament::section heading="Acting Captain 5 requests" description="Members ask to be shown as the shift's Captain 5. Approving changes only the name shown and who gets the shift's Captain 5 notifications. It never changes who can use the page.">
            <div class="space-y-6">
                <div data-testid="captain5-on-duty">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Shown as Captain 5 now, {{ $acting['shown']['date'] }}@if ($acting['shown']['shift']) ({{ $acting['shown']['shift'] }} shift)@endif</h3>
                    @if ($acting['shown']['name'] !== null)
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                            {{ $acting['shown']['name'] }}@if ($acting['shown']['rank'] !== ''), {{ $acting['shown']['rank'] }}@endif.
                            Source: {{ $acting['shown']['source'] }}.
                            @if ($acting['shown']['until'])Acting until {{ $acting['shown']['until'] }}.@endif
                        </p>
                    @else
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                            No one is shown as Captain 5 right now. New findings go to every Captain 5 seat holder until the shift's bid holder is known or an acting request is approved.
                        </p>
                    @endif
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Waiting for a decision</h3>
                    @if ($acting['pending'] === [])
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400" data-testid="captain5-acting-pending-empty">No requests are waiting.</p>
                    @else
                        <ul class="mt-1 divide-y divide-gray-200 dark:divide-white/10" data-testid="captain5-acting-pending">
                            @foreach ($acting['pending'] as $row)
                                <li class="flex flex-wrap items-start justify-between gap-3 py-3" wire:key="captain5-acting-pending-{{ $row['id'] }}">
                                    <div class="min-w-0 space-y-1">
                                        <p class="text-sm font-semibold text-gray-950 dark:text-white">
                                            @if ($row['kind'] === 'end')
                                                {{ $row['requester'] }} asks to end the acting display
                                            @else
                                                {{ $row['member'] }}@if ($row['rank'] !== '')<span class="font-normal text-gray-600 dark:text-gray-400"> · {{ $row['rank'] }}</span>@endif
                                                asks to be shown as acting Captain 5
                                            @endif
                                        </p>
                                        @if ($row['kind'] === 'act')
                                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                                {{ $row['duration'] }}, ends {{ $row['ends_at'] }}@if ($row['shift']) · {{ $row['shift'] }} shift @endif
                                                @if ($row['passed'])<x-filament::badge color="warning" size="sm" class="ml-1 inline-flex">Past its end time</x-filament::badge>@endif
                                            </p>
                                        @endif
                                        <p class="text-xs text-gray-600 dark:text-gray-400">Asked by {{ $row['requester'] }}, {{ $row['requested_at'] }}</p>
                                    </div>
                                    @if ($row['own'])
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Another administrator approves your own request.</p>
                                    @else
                                        <div class="flex items-center gap-2">
                                            {{ ($this->approveActingAction)(['request' => $row['id']]) }}
                                            {{ ($this->rejectActingAction)(['request' => $row['id']]) }}
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <details class="rounded-lg border border-gray-200 dark:border-white/10" data-testid="captain5-acting-decided">
                    <summary class="flex min-h-[44px] cursor-pointer items-center justify-between gap-2 px-4 py-2 text-sm font-semibold text-gray-950 dark:text-white">
                        <span>Recent decisions</span>
                        <span class="font-normal text-gray-600 dark:text-gray-400">{{ count($acting['decided']) }}</span>
                    </summary>
                    @if ($acting['decided'] === [])
                        <p class="border-t border-gray-200 px-4 py-3 text-sm text-gray-600 dark:border-white/10 dark:text-gray-400">No request has been decided yet.</p>
                    @else
                        <ul class="divide-y divide-gray-200 border-t border-gray-200 dark:divide-white/10 dark:border-white/10">
                            @foreach ($acting['decided'] as $row)
                                <li class="space-y-1 px-4 py-3" wire:key="captain5-acting-decided-{{ $row['id'] }}">
                                    <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                                        <x-filament::badge :color="$row['approved'] ? 'success' : 'danger'" size="sm">{{ $row['status'] }}</x-filament::badge>
                                        <span>
                                            @if ($row['kind'] === 'end')
                                                {{ $row['requester'] }}: end the acting display
                                            @else
                                                {{ $row['member'] }}: acting Captain 5 until {{ $row['ends_at'] }}
                                            @endif
                                        </span>
                                    </p>
                                    @if ($row['state'] !== '')
                                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $row['state'] }}.</p>
                                    @endif
                                    @if ($row['note'] !== '')
                                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $row['note'] }}</p>
                                    @endif
                                    <p class="text-xs text-gray-600 dark:text-gray-400">Asked by {{ $row['requester'] }}, {{ $row['requested_at'] }}. Decided by {{ $row['decided_by'] }}, {{ $row['decided_at'] }}.</p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </details>
            </div>
        </x-filament::section>

        {{-- Medical scope review --}}
        <x-filament::section heading="Medical scope review" description="Captain 5 sees Engine and Ladder issues only for items classified as medical. An item waits here until you decide, and stays hidden from Captain 5 until then.">
            <div class="space-y-4">
                @if ($pendingItems === [])
                    <p class="text-sm text-gray-700 dark:text-gray-300" data-testid="captain5-medical-empty">Nothing is waiting. Every Engine and Ladder checklist item is classified.</p>
                @else
                    <p class="text-sm font-medium text-gray-950 dark:text-white">
                        {{ count($pendingItems) }} {{ \Illuminate\Support\Str::plural('item', count($pendingItems)) }} waiting for a decision
                    </p>
                    @foreach (collect($pendingItems)->groupBy('checklist_label') as $label => $items)
                        <details class="rounded-lg border border-gray-200 dark:border-white/10" @if ($items->count() <= 12 || $items->sum('open_issues') > 0) open @endif data-testid="captain5-medical-group">
                            <summary class="flex min-h-[44px] cursor-pointer items-center justify-between gap-2 px-4 py-2 text-sm font-semibold text-gray-950 dark:text-white">
                                <span>{{ $label }}</span>
                                <span class="font-normal text-gray-600 dark:text-gray-400">{{ $items->count() }} waiting</span>
                            </summary>
                            <ul class="divide-y divide-gray-200 border-t border-gray-200 dark:divide-white/10 dark:border-white/10">
                                @foreach ($items as $item)
                                    <li class="flex flex-wrap items-start justify-between gap-3 px-4 py-3" wire:key="captain5-pending-{{ $item['checklist_type'] }}-{{ $item['item_key'] }}">
                                        <div class="min-w-0 space-y-1">
                                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $item['item_name'] }}</p>
                                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                                @if ($item['compartment']){{ $item['compartment'] }} · @endif{{ $item['reason_label'] }}
                                            </p>
                                            @if ($item['open_issues'] > 0)
                                                <x-filament::badge color="warning" size="sm">{{ $item['open_issues'] }} open {{ \Illuminate\Support\Str::plural('issue', $item['open_issues']) }} waiting</x-filament::badge>
                                            @endif
                                        </div>
                                        {{ ($this->classifyItemAction)(['type' => $item['checklist_type'], 'key' => $item['item_key']]) }}
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endforeach
                @endif

                @if ($unmatchedOpenIssues > 0)
                    <p class="text-sm text-gray-700 dark:text-gray-300" data-testid="captain5-medical-unmatched">
                        {{ $unmatchedOpenIssues }} open {{ \Illuminate\Support\Str::plural('issue', $unmatchedOpenIssues) }} could not be matched to a checklist item. They stay hidden from Captain 5 and remain with Fleet.
                    </p>
                @endif

                <details class="rounded-lg border border-gray-200 dark:border-white/10" data-testid="captain5-medical-decided">
                    <summary class="flex min-h-[44px] cursor-pointer items-center justify-between gap-2 px-4 py-2 text-sm font-semibold text-gray-950 dark:text-white">
                        <span>Decisions already made</span>
                        <span class="font-normal text-gray-600 dark:text-gray-400">{{ count($decidedItems) }}</span>
                    </summary>
                    @if ($decidedItems === [])
                        <p class="border-t border-gray-200 px-4 py-3 text-sm text-gray-600 dark:border-white/10 dark:text-gray-400">No item has been classified here yet.</p>
                    @else
                        <ul class="divide-y divide-gray-200 border-t border-gray-200 dark:divide-white/10 dark:border-white/10">
                            @foreach ($decidedItems as $item)
                                <li class="flex flex-wrap items-start justify-between gap-3 px-4 py-3" wire:key="captain5-decided-{{ $item['checklist_type'] }}-{{ $item['item_key'] }}">
                                    <div class="min-w-0 space-y-1">
                                        <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $item['item_name'] }} <span class="font-normal text-gray-600 dark:text-gray-400">· {{ $item['checklist_label'] }}</span></p>
                                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $item['classification_label'] }}. {{ $item['reason'] }}</p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400">Decided by {{ $item['decided_by'] }}, {{ $item['decided_at'] }}</p>
                                    </div>
                                    {{ ($this->changeClassificationAction)(['type' => $item['checklist_type'], 'key' => $item['item_key']]) }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </details>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
