<x-filament-panels::page>
    @php
        $report = $this->report();
        $rows = $this->filteredRows($report);
        $total = count($rows);
        $page = max(1, min($this->reportPage, max(1, (int) ceil($total / 25))));
    @endphp
    @if ($report)
        <x-filament::section>
            <div class="space-y-3">
                <p class="text-sm text-gray-600">{{ $report->source_name }} · {{ $report->created_at->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A T') }}</p>
                <p class="text-sm">{{ $report->summary['rules'] }}</p>
                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    @foreach (['workbook_rows' => 'Workbook members', 'hub_rows' => 'Hub records', 'present_both' => 'Present in both', 'workbook_only' => 'Workbook only', 'hub_only' => 'Hub only', 'conflicts' => 'Different values', 'excluded' => 'Do not add', 'missing_email' => 'Missing email'] as $key => $label)
                        <div><dt class="text-sm text-gray-600">{{ $label }}</dt><dd class="text-lg font-semibold tabular-nums">{{ $report->summary[$key] }}</dd></div>
                    @endforeach
                </dl>
                <details class="text-sm">
                    <summary class="min-h-11 cursor-pointer py-3 font-medium">Source and quality checks</summary>
                    <p class="break-all">Workbook SHA-256: {{ $report->workbook_sha256 }}</p>
                    <p>{{ implode(', ', $report->summary['sheets']) }}. Duplicate identifiers: {{ $report->summary['duplicate_ids'] }}. Assignment-view mismatches: {{ $report->summary['assignment_view_mismatches'] }}. Overrides: {{ $report->summary['assignment_overrides'] }}.</p>
                    <p>All proposed differences require review in the established employee or Bid workflow. This page has no apply action.</p>
                </details>
            </div>
        </x-filament::section>
        <div class="flex flex-col gap-3 sm:flex-row">
            <label class="flex-1 text-sm">Search members
                <x-filament::input.wrapper><x-filament::input wire:model.live.debounce.350ms="search" placeholder="Name or employee ID" /></x-filament::input.wrapper>
            </label>
            <label class="text-sm">Review category
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="classification">
                    <option value="">All categories</option>
                    @foreach (['matched' => 'Matching values', 'conflict' => 'Different values', 'workbook_only' => 'Workbook only', 'hub_only' => 'Hub only', 'excluded' => 'Do not add', 'identity_review' => 'Identity review'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
        </div>
        <p class="text-sm tabular-nums">{{ $total }} records · Page {{ $page }} of {{ max(1, (int) ceil($total / 25)) }}</p>
        <div class="space-y-3">
            @forelse (array_slice($rows, ($page - 1) * 25, 25) as $row)
                <x-filament::section compact>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-semibold">{{ $row['name'] }} <span class="text-sm font-normal tabular-nums">{{ $row['employee_id'] }}</span></p>
                        <x-filament::badge :color="$row['classification'] === 'excluded' ? 'danger' : ($row['classification'] === 'matched' ? 'success' : 'warning')">{{ str($row['classification'])->replace('_', ' ')->headline() }}</x-filament::badge>
                    </div>
                    @if ($row['flags'] !== [])<ul class="mt-2 list-inside list-disc text-sm">@foreach ($row['flags'] as $flag)<li>{{ $flag }}</li>@endforeach</ul>@endif
                    @if ($row['qa_note'])<p class="mt-2 text-sm">{{ $row['qa_note'] }}</p>@endif
                    @if (!empty($row['qa_status']))<p class="mt-2 text-sm">Directory email status: {{ $row['workbook']['email_match_status'] ?? 'Not supplied' }} · Email QA status: {{ $row['qa_status'] }}</p>@endif
                    @if (!empty($row['assignment_override']))
                        <details class="mt-2 text-sm">
                            <summary class="min-h-11 cursor-pointer py-3">Review workbook assignment override</summary>
                            <dl class="grid gap-2 sm:grid-cols-2">
                                @foreach ($row['assignment_override'] as $field => $value)
                                    <div><dt class="font-medium">{{ str($field)->headline() }}</dt><dd>{{ $value !== '' ? $value : 'Not supplied' }}</dd></div>
                                @endforeach
                            </dl>
                        </details>
                    @endif
                    @php($detailFields = $row['differences'] !== [] ? $row['differences'] : (empty($row['live']) ? array_keys($row['workbook']) : []))
                    @if ($detailFields !== [])
                        <details class="mt-2 text-sm">
                            <summary class="min-h-11 cursor-pointer py-3">{{ empty($row['live']) ? 'Review workbook proposal' : 'Review '.count($detailFields).' different fields' }}</summary>
                            <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Field</th><th class="p-2">Workbook proposal</th><th class="p-2">Hub value</th></tr></thead><tbody>
                            @foreach ($detailFields as $field)<tr class="border-t border-gray-200"><td class="p-2">{{ str($field)->replace('_', ' ')->headline() }}</td><td class="p-2">{{ ($row['workbook'][$field] ?? '') ?: 'Not supplied' }}</td><td class="p-2">{{ ($row['live'][$field] ?? '') ?: 'Not recorded' }}</td></tr>@endforeach
                            </tbody></table></div>
                        </details>
                    @endif
                </x-filament::section>
            @empty
                <p>No members match these filters.</p>
            @endforelse
        </div>
        <div class="flex justify-between gap-3">
            <x-filament::button color="gray" wire:click="$set('reportPage', {{ max(1, $page - 1) }})" :disabled="$page <= 1">Previous</x-filament::button>
            <x-filament::button color="gray" wire:click="$set('reportPage', {{ $page + 1 }})" :disabled="$page * 25 >= $total">Next</x-filament::button>
        </div>
    @else
        <x-filament::section>
            <p>No workbook comparison has been saved. Compare the supplied MBFD directory to review differences while preserving live personnel records.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
