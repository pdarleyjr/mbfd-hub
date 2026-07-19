<x-filament-panels::page>
    @php
        $latestDocument = $this->record->documents->sortByDesc('version_number')->first();
        $formLabel = $this->record->form_type === 'ics_214' ? 'ICS 214' : 'F-ROC Daily Activity Report';
    @endphp

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm xl:col-span-1">
            <p class="text-xs font-semibold uppercase tracking-wider text-primary-600">{{ $formLabel }}</p>
            <h2 class="mt-2 text-xl font-semibold text-gray-950">{{ $this->record->title }}</h2>
            <dl class="mt-6 space-y-4 text-sm">
                <div><dt class="text-gray-500">Employee</dt><dd class="font-medium text-gray-900">{{ $this->record->employee?->name }} · ID {{ $this->record->employee?->employee_id }}</dd></div>
                <div><dt class="text-gray-500">Status</dt><dd class="font-medium capitalize text-gray-900">{{ $this->record->status }}</dd></div>
                <div><dt class="text-gray-500">Last saved</dt><dd class="font-medium text-gray-900">{{ $this->record->last_autosaved_at?->format('M j, Y g:i A') }}</dd></div>
                <div><dt class="text-gray-500">Current revision</dt><dd class="font-medium text-gray-900">{{ $this->record->revision }}</dd></div>
                <div><dt class="text-gray-500">Latest PDF</dt><dd class="font-medium text-gray-900">{{ $latestDocument ? 'Version '.$latestDocument->version_number : 'Not generated' }}</dd></div>
            </dl>
            <details class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-4">
                <summary class="cursor-pointer text-sm font-semibold text-gray-800">Structured source snapshot</summary>
                <pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-words text-xs text-gray-700">{{ json_encode($this->record->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm xl:col-span-2">
            @if ($latestDocument)
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div><h2 class="text-lg font-semibold text-gray-950">Authenticated PDF preview</h2><p class="text-sm text-gray-500">{{ $latestDocument->display_name }} · SHA-256 {{ Str::limit($latestDocument->pdf_sha256, 20) }}</p></div>
                    <div class="flex gap-2">
                        <x-filament::button tag="a" color="gray" href="{{ route('admin.operational-forms.documents.preview', $latestDocument) }}" target="_blank">Open</x-filament::button>
                        <x-filament::button tag="a" href="{{ route('admin.operational-forms.documents.download', $latestDocument) }}">Download</x-filament::button>
                    </div>
                </div>
                <iframe class="h-[68vh] w-full rounded-lg border border-gray-300 bg-gray-100" src="{{ route('admin.operational-forms.documents.preview', $latestDocument) }}" title="{{ $latestDocument->display_name }} preview"></iframe>
            @else
                <div class="grid min-h-96 place-items-center rounded-lg border border-dashed border-gray-300 bg-gray-50 text-center text-gray-500"><div><x-heroicon-o-document-plus class="mx-auto h-10 w-10"/><p class="mt-3 font-medium">No PDF version has been generated.</p></div></div>
            @endif
        </section>
    </div>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-gray-950">Version history</h2>
        <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase text-gray-500"><tr><th class="px-3 py-2">Version</th><th class="px-3 py-2">Source revision</th><th class="px-3 py-2">Generated</th><th class="px-3 py-2">Checksum</th><th class="px-3 py-2">Actions</th></tr></thead><tbody class="divide-y divide-gray-100">@forelse ($this->record->documents->sortByDesc('version_number') as $document)<tr><td class="px-3 py-3 font-medium">{{ $document->version_number }}</td><td class="px-3 py-3">{{ $document->source_revision }}</td><td class="px-3 py-3">{{ $document->created_at?->format('M j, Y g:i A') }}</td><td class="px-3 py-3 font-mono text-xs">{{ Str::limit($document->pdf_sha256, 18) }}</td><td class="px-3 py-3"><a class="font-semibold text-primary-600 hover:underline" href="{{ route('admin.operational-forms.documents.preview', $document) }}" target="_blank">Preview</a><span class="mx-2 text-gray-300">|</span><a class="font-semibold text-primary-600 hover:underline" href="{{ route('admin.operational-forms.documents.download', $document) }}">Download</a></td></tr>@empty<tr><td colspan="5" class="px-3 py-8 text-center text-gray-500">No versions available.</td></tr>@endforelse</tbody></table></div>
    </section>
</x-filament-panels::page>
