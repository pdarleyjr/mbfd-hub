{{-- Filament sections and form controls only. Layout utilities, no colour literals. --}}
<x-filament-panels::page>
    @include('filament.admin.partials.ems-styles')
    <div class="space-y-6" data-testid="medical-statistics">
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white" role="note">
            {{ $headerText }}
        </div>

        <x-filament::section heading="Filters" description="Dates are Eastern time and both ends are included.">
            <div class="ems-cols ems-cols-4">
                <div>
                    <label for="stats-from" class="block text-xs font-medium text-gray-600 dark:text-gray-400">From</label>
                    <x-filament::input.wrapper><x-filament::input id="stats-from" type="date" wire:model.live.debounce.600ms="dateFrom" class="min-h-[44px]" /></x-filament::input.wrapper>
                </div>
                <div>
                    <label for="stats-to" class="block text-xs font-medium text-gray-600 dark:text-gray-400">Until</label>
                    <x-filament::input.wrapper><x-filament::input id="stats-to" type="date" wire:model.live.debounce.600ms="dateTo" class="min-h-[44px]" /></x-filament::input.wrapper>
                </div>
                <div>
                    <label for="stats-unit" class="block text-xs font-medium text-gray-600 dark:text-gray-400">Unit</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="stats-unit" wire:model.live="apparatusId" class="min-h-[44px]">
                            <option value="">All units</option>
                            @foreach ($units as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label for="stats-kind" class="block text-xs font-medium text-gray-600 dark:text-gray-400">Kind of request</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="stats-kind" wire:model.live="targetKind" class="min-h-[44px]">
                            <option value="">All kinds</option>
                            @foreach ($kinds as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <x-filament::button size="sm" color="gray" outlined wire:click="resetRange">Last 90 days</x-filament::button>
                <p class="text-xs text-gray-600 dark:text-gray-400" data-testid="filter-text">{{ $filterText }}</p>
            </div>
            @foreach ($problems as $problem)
                <p class="mt-2 text-sm font-medium text-danger-600" role="alert">{{ $problem }}</p>
            @endforeach
        </x-filament::section>

        @if ($view['empty'])
            <x-filament::section>
                <p class="text-sm text-gray-950 dark:text-white" data-testid="stats-empty">
                    There are no requests in this date range. Widen the dates or clear the unit and kind filters to see more.
                    Figures appear here once members send Medical Inventory Requests.
                </p>
            </x-filament::section>
        @else
            <x-filament::section heading="Totals" :description="$definitionsLabel.'. Each line says what it counts.'">
                <dl class="ems-cols ems-cols-3" data-testid="stats-metrics">
                    @foreach ($view['metrics'] as $metric)
                        <div wire:key="metric-{{ $loop->index }}">
                            <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $metric['label'] }}</dt>
                            <dd class="text-xl font-semibold text-gray-950 dark:text-white">{{ $metric['value'] }}</dd>
                            <dd class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ $metric['definition'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-filament::section>

            @foreach ($view['sections'] as $section)
                <x-filament::section :heading="$section->heading" :description="$section->note" wire:key="section-{{ $loop->index }}">
                    @if ($section->rows === [])
                        <p class="text-sm text-gray-600 dark:text-gray-400">Nothing to show for this range.</p>
                    @else
                        <div class="ems-tablewrap">
                            <table class="w-full text-left text-sm" data-testid="stats-{{ \Illuminate\Support\Str::slug($section->heading) }}">
                                <caption class="sr-only">{{ $section->heading }}</caption>
                                <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                                    <tr>
                                        @foreach ($section->headers as $index => $header)
                                            <th scope="col" class="px-3 py-2 font-medium first:pl-0 {{ in_array($index, $section->numericColumns, true) ? 'text-right' : '' }}">{{ $header }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                    @foreach ($section->rows as $row)
                                        <tr>
                                            @foreach ($row as $index => $cell)
                                                @if ($index === 0)
                                                    <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ $cell }}</th>
                                                @else
                                                    <td class="px-3 py-2 {{ in_array($index, $section->numericColumns, true) ? 'text-right' : '' }}">{{ $cell }}</td>
                                                @endif
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if ($section->heading === 'Monthly trend')
                            <ul class="mt-4 space-y-1.5" aria-label="Requests per month as bars" data-testid="stats-bars">
                                @foreach ($section->rows as $row)
                                    <li class="flex items-center gap-3 text-xs">
                                        <span class="w-16 shrink-0 text-gray-700 dark:text-gray-300">{{ $row[0] }}</span>
                                        <span class="ems-bar" style="width: {{ round(($row[1] / $monthlyMax) * 100) }}%; min-width: {{ $row[1] > 0 ? '2px' : '0' }}" aria-hidden="true"></span>
                                        <span class="text-gray-950 dark:text-white">{{ $row[1] }} {{ $row[1] === 1 ? 'request' : 'requests' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                </x-filament::section>
            @endforeach
        @endif
    </div>
</x-filament-panels::page>
