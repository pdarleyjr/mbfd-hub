{{-- Filament sections, badges, tabs and actions only. Layout utilities, no colour literals. --}}
<x-filament-panels::page>
    @include('filament.admin.partials.ems-styles')
    <div class="space-y-6" data-testid="medical-supply-room">
        <x-filament::tabs label="Supply room sections">
            @foreach ($tabs as $key => $label)
                <x-filament::tabs.item :active="$this->tab === $key" wire:click="setTab('{{ $key }}')" wire:key="tab-{{ $key }}">
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        {{-- ============================== STOCK ============================== --}}
        @if ($this->tab === 'stock')
            {{ $this->table }}

            <x-filament::section heading="Tracked through the controlled-substance procedure"
                description="These are not counted here and have no stock actions. Narcotics are restocked through the department's controlled-substance procedure.">
                @if ($controlled === [])
                    <p class="text-sm text-gray-600 dark:text-gray-400">No controlled items are listed on the supply room sheet.</p>
                @else
                    <ul class="ems-cols ems-cols-2 text-sm" data-testid="controlled-items">
                        @foreach ($controlled as $item)
                            <li class="text-gray-950 dark:text-white">{{ $item['name'] }}@if ($item['location']) <span class="text-gray-600 dark:text-gray-400">({{ $item['location'] }})</span>@endif</li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        @endif

        {{-- ============================== COUNTS ============================== --}}
        @if ($this->tab === 'counts')
            @if ($detail === null)
                <x-filament::section heading="Counts" description="A count is a physical count of the shelves. Start one, enter what you see, leave and come back, then finalize.">
                    <x-slot name="headerEnd">{{ $this->startCountAction }}</x-slot>
                    @if ($drafts === [])
                        <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="no-drafts">No count is in progress. @if ($canAct) Use Start a count to begin. @endif</p>
                    @else
                        <ul class="divide-y divide-gray-200 dark:divide-white/10" data-testid="count-drafts">
                            @foreach ($drafts as $draft)
                                <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="draft-{{ $draft['id'] }}">
                                    <div class="text-sm">
                                        <p class="font-medium text-gray-950 dark:text-white">{{ $draft['scope'] }}</p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400">Started {{ $draft['started'] }}. {{ $draft['entered'] }} {{ $draft['entered'] === 1 ? 'item' : 'items' }} entered so far.</p>
                                    </div>
                                    <x-filament::button size="sm" color="gray" wire:click="openCount({{ $draft['id'] }})">Resume</x-filament::button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-filament::section>
            @else
                <x-filament::section :heading="'Counting: '.($detail['scope_location_code'] ?? 'All locations')"
                    description="Type what is on the shelf. Use cases or boxes only where the package size is known. Leave a line blank if you did not count it.">
                    <x-slot name="headerEnd">
                        <x-filament::button size="sm" color="gray" outlined wire:click="closeCount">Back to counts</x-filament::button>
                    </x-slot>

                    <div class="ems-count-head border-b border-gray-200 pb-2 text-xs font-medium text-gray-600 dark:border-white/10 dark:text-gray-400">
                        <div class="ems-head-item">Item</div>
                        <div class="ems-head-field">Cases</div>
                        <div class="ems-head-field">Boxes or packs</div>
                        <div class="ems-head-field">Each</div>
                        <div class="ems-head-field">Saved as</div>
                    </div>
                    <ul class="divide-y divide-gray-200 dark:divide-white/10" data-testid="count-items">
                        @foreach ($detail['items'] as $item)
                            @php
                                $perPackage = $item['units_per_package'];
                                $unit = $item['package_unit'];
                                $showCases = $perPackage !== null && ($unit === 'case' || $item['packages_per_case'] !== null);
                                $showBoxes = $perPackage !== null && $unit !== 'case';
                                $boxLabel = in_array($unit, [null, 'each'], true) ? 'Packages' : ucfirst($unit).'s';
                                $eachLabel = ($showCases || $showBoxes) ? 'Loose (each)' : 'Count (units)';
                                $saved = $item['quantity_base'] !== null
                                    ? '= '.number_format($item['quantity_base']).' units'
                                    : (in_array('unknown_conversion', $item['warnings'], true) ? 'Size not stated, so not counted' : '');
                            @endphp
                            <li class="ems-count py-3" wire:key="count-item-{{ $item['id'] }}">
                                <div class="ems-item">
                                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $item['name'] }}</p>
                                    <p class="text-xs text-gray-600 dark:text-gray-400">{{ $item['location_code'] }} · {{ \App\Filament\Support\EmsLabels::packageText($unit, $perPackage, $item['packages_per_case']) }}</p>
                                </div>
                                <div class="ems-field">
                                    @if ($showCases)
                                        <label class="ems-label" for="cases-{{ $item['id'] }}">Cases</label>
                                        <x-filament::input.wrapper><x-filament::input id="cases-{{ $item['id'] }}" type="number" min="0" inputmode="numeric" wire:model="entries.{{ $item['id'] }}.cases" class="min-h-[44px]" /></x-filament::input.wrapper>
                                    @endif
                                </div>
                                <div class="ems-field">
                                    @if ($showBoxes)
                                        <label class="ems-label" for="boxes-{{ $item['id'] }}">{{ $boxLabel }}</label>
                                        <x-filament::input.wrapper><x-filament::input id="boxes-{{ $item['id'] }}" type="number" min="0" inputmode="numeric" wire:model="entries.{{ $item['id'] }}.boxes" class="min-h-[44px]" /></x-filament::input.wrapper>
                                    @endif
                                </div>
                                <div class="ems-field">
                                    <label class="ems-label" for="each-{{ $item['id'] }}">{{ $eachLabel }}</label>
                                    <x-filament::input.wrapper><x-filament::input id="each-{{ $item['id'] }}" type="number" min="0" inputmode="numeric" wire:model="entries.{{ $item['id'] }}.each" class="min-h-[44px]" /></x-filament::input.wrapper>
                                </div>
                                <div class="ems-saved text-xs text-gray-700 dark:text-gray-300">{{ $saved }}</div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($detail['items'] === [])
                        <p class="text-sm text-gray-600 dark:text-gray-400">There are no items in this location.</p>
                    @endif

                    @if ($canAct)
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            {{ $this->saveCountAction }}
                            {{ $this->finalizeCountAction }}
                            {{ $this->discardCountAction }}
                        </div>
                    @endif
                </x-filament::section>
            @endif
        @endif

        {{-- ============================== RECONCILE ============================== --}}
        @if ($this->tab === 'reconcile')
            <x-filament::section heading="Calculated snapshot of supplies issued since the last inventory"
                description="Works out what should be on the shelf now: the last count, minus what was issued, plus what was received, plus adjustments. Nothing is saved until you confirm.">
                @if ($canAct && ! $preview['nothing_to_reconcile'])
                    <x-slot name="headerEnd">{{ $this->confirmReconciliationAction }}</x-slot>
                @endif

                <p class="mb-4 text-sm text-gray-700 dark:text-gray-300" data-testid="calculated-snapshot-note">
                    This is a calculation, not a count. Reconciling submitted inventory forms is done on the Captain 5 page.
                </p>

                <div class="space-y-4" data-testid="reconcile-preview">
                    @if ($preview['nothing_to_reconcile'])
                        <p class="text-sm text-gray-700 dark:text-gray-300" data-testid="nothing-to-reconcile">
                            @if ($preview['previous_snapshot'])
                                Nothing new to reconcile. The latest snapshot ({{ $previousCount }}) already includes every stock movement.
                            @else
                                There are no supply room items to reconcile yet.
                            @endif
                        </p>
                    @else
                        <p class="text-sm text-gray-700 dark:text-gray-300">
                            @if ($preview['previous_snapshot']) Latest snapshot: {{ $previousCount }}. @else No snapshot exists yet. @endif
                            {{ $preview['summary']['movements_included'] }} stock {{ $preview['summary']['movements_included'] === 1 ? 'movement' : 'movements' }} since then.
                            @if ($preview['summary']['without_previous_count'] > 0) {{ $preview['summary']['without_previous_count'] }} items have no earlier count and stay "not counted". @endif
                            @if ($preview['summary']['negative_results'] > 0) <span class="font-medium">{{ $preview['summary']['negative_results'] }} would be below zero. Check them before you confirm.</span> @endif
                        </p>

                        <label class="flex min-h-[44px] items-center gap-2 text-sm">
                            <x-filament::input.checkbox wire:model.live="changesOnly" />
                            <span>Show only items with movements or warnings @if ($hiddenRows > 0)({{ $hiddenRows }} unchanged items hidden)@endif</span>
                        </label>

                        <div class="ems-tablewrap">
                            <table class="ems-wide w-full text-left text-sm">
                                <caption class="sr-only">Reconciliation preview</caption>
                                <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                                    <tr>
                                        <th scope="col" class="py-2 pr-3 font-medium">Item</th>
                                        <th scope="col" class="px-3 py-2 text-right font-medium">Previous count</th>
                                        <th scope="col" class="px-3 py-2 text-right font-medium">Issued</th>
                                        <th scope="col" class="px-3 py-2 text-right font-medium">Received</th>
                                        <th scope="col" class="px-3 py-2 text-right font-medium">Adjustments</th>
                                        <th scope="col" class="px-3 py-2 text-right font-medium">Calculated</th>
                                        <th scope="col" class="px-3 py-2 text-right font-medium">Threshold</th>
                                        <th scope="col" class="px-3 py-2 font-medium">Warnings</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                    @foreach ($previewRows as $row)
                                        <tr wire:key="recon-{{ $row['supply_item_id'] }}">
                                            <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ $row['name'] }} <span class="font-normal text-gray-600 dark:text-gray-400">{{ $row['location_code'] }}</span></th>
                                            <td class="px-3 py-2 text-right">{{ $row['previous_quantity'] ?? 'None' }}@if ($row['previous_quantity'] !== null && $row['previous_basis_label'])<span class="block text-xs text-gray-600 dark:text-gray-400">{{ $row['previous_basis_label'] }}</span>@endif</td>
                                            <td class="px-3 py-2 text-right">{{ $row['issued'] }}</td>
                                            <td class="px-3 py-2 text-right">{{ $row['received'] }}</td>
                                            <td class="px-3 py-2 text-right">{{ $row['adjusted'] }}</td>
                                            <td class="px-3 py-2 text-right font-medium {{ $row['calculated_quantity'] !== null && $row['calculated_quantity'] < 0 ? 'text-danger-600' : '' }}">{{ $row['calculated_quantity'] ?? 'Not counted' }}</td>
                                            <td class="px-3 py-2 text-right">{{ $row['threshold_qty_base'] ?? 'Not set' }}@if ($row['below_threshold'] === true) <x-filament::badge color="danger" size="sm">Below</x-filament::badge>@endif</td>
                                            <td class="px-3 py-2">
                                                <div class="flex flex-wrap gap-1">
                                                    @foreach ($row['warnings'] as $code)
                                                        <x-filament::badge color="warning" size="sm">{{ \App\Filament\Support\EmsLabels::warning($code) }}</x-filament::badge>
                                                    @endforeach
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                    @if ($previewRows === [])
                                        <tr><td colspan="8" class="py-4 text-center text-gray-600 dark:text-gray-400">No items have movements or warnings.</td></tr>
                                    @endif
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($preview['unmatched_issuances'] !== [])
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10" data-testid="unmatched-issuances">
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Issued but not taken from stock</h3>
                            <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">These issues were recorded on the request only, so the calculation above does not include them. Correct the issuance on the request to attach a supply room item.</p>
                            <ul class="mt-2 space-y-1 text-sm">
                                @foreach ($preview['unmatched_issuances'] as $issue)
                                    <li>{{ $issue['request_number'] }}: {{ $issue['quantity'] }} of {{ $issue['label'] }}. <span class="text-gray-600 dark:text-gray-400">{{ $issue['note'] }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif

        {{-- ============================== REORDER ============================== --}}
        @if ($this->tab === 'reorder')
            <x-filament::section heading="Reorder recommended" :description="$advice['note']">
                @if ($advice['advisories'] === [])
                    <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="no-advisories">Nothing is below its threshold right now.</p>
                @else
                    <div class="ems-tablewrap">
                        <table class="ems-wide w-full text-left text-sm" data-testid="advisories">
                            <caption class="sr-only">Items below their reorder threshold</caption>
                            <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                                <tr>
                                    <th scope="col" class="py-2 pr-3 font-medium">Item</th>
                                    <th scope="col" class="px-3 py-2 text-right font-medium">On hand</th>
                                    <th scope="col" class="px-3 py-2 text-right font-medium">Threshold</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Suggested order</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Supplier (Historical, unverified)</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Status</th>
                                    <th scope="col" class="py-2 pl-3 text-right font-medium"><span class="sr-only">Action</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @foreach ($advice['advisories'] as $row)
                                    <tr wire:key="advisory-{{ $row['supply_item_id'] }}">
                                        <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ $row['name'] }} <span class="font-normal text-gray-600 dark:text-gray-400">{{ $row['location_code'] }}</span></th>
                                        <td class="px-3 py-2 text-right">{{ $row['current_quantity'] }}<span class="block text-xs text-gray-600 dark:text-gray-400">{{ $row['current_basis_label'] }}</span></td>
                                        <td class="px-3 py-2 text-right">{{ $row['threshold_qty_base'] }}<span class="block text-xs text-gray-600 dark:text-gray-400">{{ $row['threshold_source_text'] }}</span></td>
                                        <td class="px-3 py-2">
                                            @if ($row['suggested_packages'] !== null)
                                                {{ $row['suggested_packages'] }} {{ $row['package_unit'] ?? 'package' }}{{ $row['suggested_packages'] === 1 ? '' : 's' }}
                                                <span class="block text-xs text-gray-600 dark:text-gray-400">{{ $row['suggested_basis'] === 'source_order_amount' ? 'From the sheet: '.$row['order_amount_text'] : 'Shortfall rounded up to whole packages' }}</span>
                                            @else
                                                <span class="text-gray-600 dark:text-gray-400">Package size not stated</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">{{ $row['supplier_code'] ?? 'None listed' }}@if ($row['item_number']) <span class="block text-xs text-gray-600 dark:text-gray-400">Item {{ $row['item_number'] }}</span>@endif</td>
                                        <td class="px-3 py-2">
                                            <x-filament::badge color="warning">Reorder recommended</x-filament::badge>
                                            @foreach ($row['open_orders'] as $open)
                                                <x-filament::badge color="info" class="mt-1">Order placed: {{ $open['packages'] }} {{ $open['packages'] === 1 ? 'package' : 'packages' }}</x-filament::badge>
                                            @endforeach
                                        </td>
                                        <td class="py-2 pl-3 text-right">{{ ($this->recordOrderAction)(['item' => $row['supply_item_id']]) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($advice['needs_count'] !== [])
                    <div class="mt-4 text-sm" data-testid="needs-count">
                        <h3 class="font-semibold text-gray-950 dark:text-white">Needs a count first</h3>
                        <p class="text-xs text-gray-600 dark:text-gray-400">These have a threshold but nobody has counted them yet, so there is nothing to compare.</p>
                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ collect($advice['needs_count'])->pluck('name')->implode(', ') }}</p>
                    </div>
                @endif
                @if ($advice['unknown_threshold'] !== [])
                    <div class="mt-4 text-sm" data-testid="unknown-threshold">
                        <h3 class="font-semibold text-gray-950 dark:text-white">Threshold not known</h3>
                        <p class="text-xs text-gray-600 dark:text-gray-400">The sheet's threshold could not be turned into a number without guessing. Open the item on the Stock tab and set it.</p>
                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ collect($advice['unknown_threshold'])->pluck('name')->implode(', ') }}</p>
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section heading="Orders" description="An order exists only when a person recorded that it was placed. Marking it received adds the amount to stock.">
                <div class="mb-3 max-w-xs">
                    <label for="order-status" class="block text-xs font-medium text-gray-600 dark:text-gray-400">Show</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="order-status" wire:model.live="orderStatus">
                            <option value="placed">Open orders (placed)</option>
                            <option value="received">Received</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="">All</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                @if ($orders === [])
                    <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="no-orders">No orders to show.</p>
                @else
                    <div class="ems-tablewrap">
                        <table class="ems-medium w-full text-left text-sm" data-testid="orders">
                            <caption class="sr-only">Supply orders</caption>
                            <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                                <tr>
                                    <th scope="col" class="py-2 pr-3 font-medium">Item</th>
                                    <th scope="col" class="px-3 py-2 text-right font-medium">Packages</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Placed</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Status</th>
                                    <th scope="col" class="py-2 pl-3 text-right font-medium"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @foreach ($orders as $order)
                                    <tr wire:key="order-{{ $order['id'] }}">
                                        <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ $order['item_name'] }}@if ($order['notes'])<span class="block text-xs font-normal text-gray-600 dark:text-gray-400">{{ $order['notes'] }}</span>@endif</th>
                                        <td class="px-3 py-2 text-right">{{ $order['packages'] }}@if ($order['quantity_base'])<span class="block text-xs text-gray-600 dark:text-gray-400">{{ $order['quantity_base'] }} units</span>@endif</td>
                                        <td class="px-3 py-2">{{ $order['placed_text'] }}@if ($order['placed_by_name'])<span class="block text-xs text-gray-600 dark:text-gray-400">{{ $order['placed_by_name'] }}</span>@endif</td>
                                        <td class="px-3 py-2"><x-filament::badge :color="$order['status'] === 'placed' ? 'info' : ($order['status'] === 'received' ? 'success' : 'gray')">{{ $order['status_text'] }}</x-filament::badge>@if ($order['received_text'])<span class="block text-xs text-gray-600 dark:text-gray-400">{{ $order['received_text'] }}</span>@endif</td>
                                        <td class="py-2 pl-3 text-right">
                                            @if ($order['status'] === 'placed' && $canAct)
                                                <div class="flex flex-wrap justify-end gap-2">
                                                    {{ ($this->receiveOrderAction)(['order' => $order['id']]) }}
                                                    {{ ($this->cancelOrderAction)(['order' => $order['id']]) }}
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endif

        {{-- ============================== MOVEMENTS ============================== --}}
        @if ($this->tab === 'movements')
            <x-filament::section heading="Stock movements" description="The ledger. Every issue, receipt and adjustment is a line. Nothing here is edited; a correction is a new line.">
                <div class="mb-3 max-w-xs">
                    <label for="movement-type" class="block text-xs font-medium text-gray-600 dark:text-gray-400">Show</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="movement-type" wire:model.live="movementType">
                            <option value="">All movements</option>
                            @foreach ($movementTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                @if ($movements === [])
                    <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="no-movements">No stock movements yet. They appear when supplies are issued or received.</p>
                @else
                    <div class="ems-tablewrap">
                        <table class="ems-medium w-full text-left text-sm" data-testid="movements">
                            <caption class="sr-only">Stock movements, newest first</caption>
                            <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                                <tr>
                                    <th scope="col" class="py-2 pr-3 font-medium">When (Eastern)</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Item</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Type</th>
                                    <th scope="col" class="px-3 py-2 text-right font-medium">Units</th>
                                    <th scope="col" class="px-3 py-2 font-medium">By</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Reason or request</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @foreach ($movements as $movement)
                                    <tr wire:key="movement-{{ $movement['id'] }}">
                                        <td class="py-2 pr-3">{{ $movement['at'] }}</td>
                                        <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $movement['item_name'] }}</td>
                                        <td class="px-3 py-2">{{ $movement['type_text'] }}</td>
                                        <td class="px-3 py-2 text-right">{{ $movement['quantity_base'] > 0 ? '+' : '' }}{{ $movement['quantity_base'] }}</td>
                                        <td class="px-3 py-2">{{ $movement['actor_name'] }}</td>
                                        <td class="px-3 py-2">
                                            @if ($movement['request_url'])<a href="{{ $movement['request_url'] }}" class="text-primary-600 underline">{{ $movement['request_number'] }}</a>@endif
                                            {{ $movement['reason'] }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3 flex items-center justify-between text-sm">
                        <x-filament::button size="sm" color="gray" outlined wire:click="movementsGo(-1)" :disabled="$movementMeta['page'] <= 1">Newer</x-filament::button>
                        <span class="text-gray-600 dark:text-gray-400">Page {{ $movementMeta['page'] }} of {{ $movementMeta['last_page'] }} ({{ number_format($movementMeta['total']) }} movements)</span>
                        <x-filament::button size="sm" color="gray" outlined wire:click="movementsGo(1)" :disabled="$movementMeta['page'] >= $movementMeta['last_page']">Older</x-filament::button>
                    </div>
                @endif
            </x-filament::section>
        @endif

        {{-- ============================== SNAPSHOTS ============================== --}}
        @if ($this->tab === 'snapshots')
            @if ($snapshotDetail === null)
                <x-filament::section heading="Snapshots" description="Saved counts and reconciliations. A final snapshot never changes.">
                    @if ($snapshots === [])
                        <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="no-snapshots">No counts or reconciliations have been saved yet.</p>
                    @else
                        <ul class="divide-y divide-gray-200 dark:divide-white/10" data-testid="snapshots">
                            @foreach ($snapshots as $snapshot)
                                <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="snapshot-{{ $snapshot['id'] }}">
                                    <div class="text-sm">
                                        <p class="font-medium text-gray-950 dark:text-white">{{ $snapshot['kind_text'] }}@if ($snapshot['version']) <span class="font-normal text-gray-600 dark:text-gray-400">version {{ $snapshot['version'] }}</span>@endif
                                            <x-filament::badge size="sm" :color="$snapshot['status'] === 'final' ? 'success' : 'warning'">{{ $snapshot['status'] === 'final' ? 'Final' : 'Draft' }}</x-filament::badge></p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400">{{ $snapshot['scope_location_code'] ?? 'All locations' }} · {{ $snapshot['when'] }}@if ($snapshot['finalized_name']) · {{ $snapshot['finalized_name'] }}@endif · {{ $snapshot['counted_count'] }} of {{ $snapshot['line_count'] }} items have a number</p>
                                    </div>
                                    <x-filament::button size="sm" color="gray" wire:click="viewSnapshot('{{ $snapshot['id'] }}')">View</x-filament::button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-filament::section>
            @else
                <x-filament::section :heading="$snapshotDetail['kind_label'].($snapshotDetail['version'] ? ', version '.$snapshotDetail['version'] : ', draft')"
                    :description="$snapshotDetail['kind'] === 'reconciliation' ? 'Calculated from the last counts and the movements since. This is not a physical count.' : ($snapshotDetail['finalized_name'] ? 'Counted by '.$snapshotDetail['finalized_name'].'.' : 'Draft, not final yet.')">
                    <x-slot name="headerEnd">
                        <div class="flex flex-wrap items-center gap-2">
                            {{ ($this->exportSnapshotAction)(['snapshot' => $snapshotDetail['id'], 'format' => 'xlsx']) }}
                            {{ ($this->exportSnapshotAction)(['snapshot' => $snapshotDetail['id'], 'format' => 'pdf']) }}
                            <x-filament::button size="sm" color="gray" outlined wire:click="closeSnapshot">Back</x-filament::button>
                        </div>
                    </x-slot>
                    <div class="ems-tablewrap">
                        <table class="ems-narrow w-full text-left text-sm" data-testid="snapshot-lines">
                            <caption class="sr-only">Snapshot lines</caption>
                            <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                                <tr>
                                    <th scope="col" class="py-2 pr-3 font-medium">Item</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Location</th>
                                    <th scope="col" class="px-3 py-2 text-right font-medium">Units</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Basis</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @foreach ($snapshotDetail['lines'] as $line)
                                    <tr>
                                        <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ $line['item_name'] }}</th>
                                        <td class="px-3 py-2">{{ $line['location_code'] }}</td>
                                        <td class="px-3 py-2 text-right">{{ $line['quantity_base'] ?? 'None' }}</td>
                                        <td class="px-3 py-2">{{ $valueSources[$line['value_source']] ?? $line['value_source'] }}</td>
                                        <td class="px-3 py-2">{{ collect($line['warnings'])->map(fn ($code) => \App\Filament\Support\EmsLabels::warning($code))->implode('; ') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif
        @endif
    </div>
</x-filament-panels::page>
