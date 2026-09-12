<x-filament-panels::page>
    @if ($survey)
        <div class="space-y-6">
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"><div class="flex items-start justify-between gap-4"><div><h1 class="text-xl font-semibold">{{ $survey->title }}</h1><p class="text-sm text-gray-600">{{ $survey->workgroup->name }} · {{ $survey->status }} · Data through {{ $analytics['generated_at'] ?? '' }}</p></div><x-filament::button wire:click="generateExecutiveReport">Generate Executive Report</x-filament::button></div><dl class="mt-5 grid gap-3 sm:grid-cols-4">@foreach($analytics['summary'] ?? [] as $label => $value)<div class="rounded-lg bg-gray-50 p-3"><dt class="text-xs uppercase text-gray-500">{{ str_replace('_', ' ', $label) }}</dt><dd class="mt-1 font-semibold">{{ $value ?? '—' }}@if($label === 'response_rate' && $value !== null)%@endif</dd></div>@endforeach</dl></section>
            @if($report?->executive_narrative)<section class="rounded-xl border border-blue-200 bg-blue-50 p-5"><h2 class="font-semibold">Executive summary</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $report->executive_narrative }}</p></section>@endif
            @foreach($analytics['questions'] ?? [] as $question)<section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"><h2 class="font-semibold">{{ $question['position'] }}. {{ $question['prompt'] }}</h2><pre class="mt-3 overflow-auto rounded bg-gray-50 p-3 text-xs">{{ json_encode($question['metrics'], JSON_PRETTY_PRINT) }}</pre></section>@endforeach
        </div>
    @endif
</x-filament-panels::page>

