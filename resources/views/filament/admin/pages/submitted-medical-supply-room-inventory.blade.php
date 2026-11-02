{{--
    Filament sections, badges and buttons only. Rows are CSS grids, not tables: on a phone every row
    stacks with its labels, so nothing scrolls sideways. Layout only here; colours come from Filament.
--}}
<x-filament-panels::page>
    <style>
        .smr-label { display: block; font-size: .75rem; line-height: 1rem; opacity: .75; }
        .smr-head { display: none; }
        .smr-row { display: grid; gap: .375rem .75rem; grid-template-columns: repeat(2, minmax(0, 1fr)); padding-block: .75rem; }
        .smr-row > .smr-when, .smr-row > .smr-act { grid-column: 1 / -1; }
        .smr-row > * { min-width: 0; overflow-wrap: anywhere; }
        .smr-line { display: grid; gap: .25rem .75rem; grid-template-columns: repeat(2, minmax(0, 1fr)); padding-block: .625rem; }
        .smr-line > .smr-item { grid-column: 1 / -1; }
        .smr-line > * { min-width: 0; overflow-wrap: anywhere; }
        .smr-facts { display: grid; gap: .75rem 1.5rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .smr-controls { display: grid; gap: .75rem; grid-template-columns: minmax(0, 1fr); }

        @media (min-width: 640px) {
            .smr-line { grid-template-columns: minmax(0, 6fr) minmax(0, 3fr) minmax(0, 3fr); align-items: start; }
            .smr-line > .smr-item { grid-column: auto; }
            .smr-line-head { display: grid; gap: .75rem; grid-template-columns: minmax(0, 6fr) minmax(0, 3fr) minmax(0, 3fr); }
            .smr-line .smr-label { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }
            .smr-facts { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .smr-controls { grid-template-columns: minmax(0, 2fr) auto; align-items: center; }
        }
        @media (min-width: 768px) {
            .smr-head, .smr-row { display: grid; gap: .75rem; align-items: center; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr) minmax(0, 1.3fr) minmax(0, 1.7fr) minmax(0, 1.3fr) auto; }
            .smr-row > .smr-when, .smr-row > .smr-act { grid-column: auto; }
            .smr-row .smr-label { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }
        }
        @media (max-width: 639px) { .smr-line-head { display: none; } }
    </style>

    <div class="space-y-6" data-testid="submitted-supply-inventory">
        @if ($report === null)
            {{-- ============================== LIST ============================== --}}
            <x-filament::section heading="Submitted counts" description="Newest first. Open a count to see it by shelf, with what to order.">
                @if ($listing['rows'] === [])
                    <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="no-submitted-counts">
                        No counts have been submitted yet. Captain 5 submits one from the Master Supply Room Inventory on the Captain 5 page.
                    </p>
                @else
                    <div class="smr-head border-b border-gray-200 pb-2 text-xs font-medium text-gray-600 dark:border-white/10 dark:text-gray-400" aria-hidden="true">
                        <div>Date and time</div>
                        <div>Counted by</div>
                        <div>Items counted</div>
                        <div>Not counted</div>
                        <div>Items to order</div>
                        <div></div>
                    </div>
                    <ul class="divide-y divide-gray-200 dark:divide-white/10" data-testid="submitted-counts">
                        @foreach ($listing['rows'] as $row)
                            <li class="smr-row" wire:key="count-{{ $row['id'] }}" data-testid="submitted-count">
                                <div class="smr-when">
                                    <span class="smr-label">Date and time</span>
                                    <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $row['when'] }}</span>
                                    @if ($row['latest'])
                                        <x-filament::badge color="success" size="sm" class="ms-1 inline-flex">Latest</x-filament::badge>
                                    @endif
                                </div>
                                <div class="text-sm text-gray-950 dark:text-white">
                                    <span class="smr-label">Counted by</span>
                                    {{ $row['counted_by'] }}
                                </div>
                                <div class="text-sm text-gray-950 dark:text-white">
                                    <span class="smr-label">Items counted</span>
                                    {{ $row['counted'] === null ? $dash : number_format($row['counted']) }}
                                </div>
                                <div class="text-sm text-gray-950 dark:text-white">
                                    <span class="smr-label">Not counted</span>
                                    {{ $row['uncounted'] === null ? $dash : number_format($row['uncounted']) }}
                                    @if ($row['partial'])
                                        <x-filament::badge color="warning" size="sm" class="ms-1 inline-flex">Partial</x-filament::badge>
                                    @endif
                                </div>
                                <div class="text-sm text-gray-950 dark:text-white">
                                    <span class="smr-label">Items to order</span>
                                    {{ $row['to_order'] === null ? $dash : number_format($row['to_order']) }}
                                </div>
                                <div class="smr-act">
                                    <x-filament::button size="sm" color="gray" wire:click="openReport('{{ $row['id'] }}')" class="min-h-[44px]">Open</x-filament::button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($listing['has_more'])
                        <div class="mt-3">
                            <x-filament::button size="sm" color="gray" outlined wire:click="showMore" class="min-h-[44px]">Show older counts</x-filament::button>
                        </div>
                    @endif
                @endif
            </x-filament::section>
        @else
            {{-- ============================== REPORT ============================== --}}
            <x-filament::section>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white" data-testid="report-heading">Count of {{ $report['counted_at'] }}</h2>
                        <p class="mt-0.5 text-sm text-gray-700 dark:text-gray-300" data-testid="report-counted-by">Counted by {{ $report['counted_by'] }}</p>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300" data-testid="report-coverage">
                            {{ $report['coverage'] }}
                            @if ($report['partial'])
                                <x-filament::badge color="warning" size="sm" class="ms-1 inline-flex">Partial</x-filament::badge>
                            @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        {{ ($this->exportReportAction)(['report' => $report['id'], 'format' => 'xlsx']) }}
                        {{ ($this->exportReportAction)(['report' => $report['id'], 'format' => 'pdf']) }}
                        <x-filament::button size="sm" color="gray" outlined wire:click="closeReport">Back to counts</x-filament::button>
                    </div>
                </div>

                <dl class="smr-facts mt-4 text-sm">
                    <div>
                        <dt class="text-xs font-medium text-gray-600 dark:text-gray-400">Items counted</dt>
                        <dd class="text-gray-950 dark:text-white">{{ $report['counted_items'] === null ? $dash : number_format($report['counted_items']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-600 dark:text-gray-400">Not counted</dt>
                        <dd class="text-gray-950 dark:text-white">{{ $report['uncounted_items'] === null ? $dash : number_format($report['uncounted_items']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-600 dark:text-gray-400">Items to order</dt>
                        <dd class="text-gray-950 dark:text-white" data-testid="report-to-order">{{ number_format((int) $report['to_order']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-600 dark:text-gray-400">Lines in this report</dt>
                        <dd class="text-gray-950 dark:text-white">{{ number_format($report['total_rows']) }}</dd>
                    </div>
                </dl>

                <p class="mt-4 text-sm text-gray-700 dark:text-gray-300" data-testid="not-a-purchase-order">{{ $notAPurchaseOrder }}</p>
                <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                    Placed an order? Record it under
                    <a href="{{ $reorderUrl }}" class="font-medium text-primary-600 underline dark:text-primary-400" data-testid="reorder-link">Reorder on the Medical Supply Room page</a>.
                </p>
            </x-filament::section>

            <div class="smr-controls">
                <x-filament::input.wrapper>
                    <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Search items in this count" maxlength="100" aria-label="Search items in this count" />
                </x-filament::input.wrapper>
                <label class="flex min-h-[44px] items-center gap-2 text-sm text-gray-950 dark:text-white">
                    <x-filament::input.checkbox wire:model.live="onlyToOrder" />
                    <span>Only items to order</span>
                </label>
            </div>

            @forelse ($report['shelves'] as $shelf)
                <x-filament::section :heading="$shelf['title']" :description="$shelf['label']" wire:key="shelf-{{ $shelf['code'] ?? 'none' }}" data-testid="report-shelf">
                    <div class="smr-line-head border-b border-gray-200 pb-2 text-xs font-medium text-gray-600 dark:border-white/10 dark:text-gray-400" aria-hidden="true">
                        <div>Item</div>
                        <div>Counted</div>
                        <div>Order</div>
                    </div>
                    <ul class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($shelf['rows'] as $row)
                            <li class="smr-line" wire:key="line-{{ $shelf['code'] ?? 'none' }}-{{ $loop->index }}" data-testid="report-row">
                                <div class="smr-item">
                                    <span class="smr-label">Item</span>
                                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $row['item'] }}</p>
                                    @if ($row['note'])
                                        <p class="text-xs text-gray-600 dark:text-gray-400" data-testid="report-note">{{ $row['note'] }}</p>
                                    @endif
                                </div>
                                <div class="text-sm text-gray-950 dark:text-white">
                                    <span class="smr-label">Counted</span>
                                    {{ $row['counted_text'] }}
                                </div>
                                <div class="text-sm">
                                    <span class="smr-label">Order</span>
                                    @if ($row['order_kind'] === 'order')
                                        <x-filament::badge color="warning" class="inline-flex">{{ $row['order_text'] }}</x-filament::badge>
                                    @elseif ($row['order_kind'] === 'problem')
                                        <span class="font-medium text-danger-600 dark:text-danger-400">{{ $row['order_text'] }}</span>
                                    @else
                                        <span class="text-gray-700 dark:text-gray-300">{{ $row['order_text'] }}</span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @empty
                <x-filament::section>
                    <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="report-empty">
                        @if ($report['total_rows'] === 0)
                            This count has no lines.
                        @else
                            No items match. Clear the search or turn off "Only items to order".
                        @endif
                    </p>
                </x-filament::section>
            @endforelse
        @endif
    </div>
</x-filament-panels::page>
