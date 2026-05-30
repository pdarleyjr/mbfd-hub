<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Add a document to the chatbot's knowledge base</x-slot>
            <x-slot name="description">
                Uploaded documents are indexed for the MBFD Support chatbot (the assistant on the
                public landing page). It will reference and cite them by filename. Supported:
                PDF, DOCX, TXT, MD, CSV. Scanned/image-only PDFs can't be read — upload a
                text-based version.
            </x-slot>

            <form wire:submit="upload" class="space-y-4">
                {{ $this->form }}

                <x-filament::button
                    type="submit"
                    icon="heroicon-o-arrow-up-tray"
                    wire:loading.attr="disabled"
                    wire:target="upload,data.document"
                >
                    <span wire:loading.remove wire:target="upload">Upload &amp; ingest</span>
                    <span wire:loading wire:target="upload">Ingesting…</span>
                </x-filament::button>
            </form>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Indexed documents</x-slot>
            <x-slot name="description">Documents currently available to the chatbot. Removing one deletes its indexed content.</x-slot>

            @php($docs = $this->documents)

            @if ($docs->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No admin-uploaded documents yet. (Apparatus manuals &amp; the SOG ingested earlier are part of the base knowledge and not listed here.)</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <th class="py-2 pr-4 font-medium">Document</th>
                                <th class="py-2 pr-4 font-medium">Chunks</th>
                                <th class="py-2 pr-4 font-medium">Size</th>
                                <th class="py-2 pr-4 font-medium">Uploaded by</th>
                                <th class="py-2 pr-4 font-medium">When</th>
                                <th class="py-2 pr-4 font-medium text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($docs as $doc)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4 font-medium text-gray-900 dark:text-gray-100">
                                        <x-filament::icon icon="heroicon-o-document-text" class="inline-block h-4 w-4 mr-1 text-gray-400" />
                                        {{ $doc->filename }}
                                    </td>
                                    <td class="py-2 pr-4">{{ $doc->chunk_count }}</td>
                                    <td class="py-2 pr-4">{{ number_format($doc->size / 1024, 0) }} KB</td>
                                    <td class="py-2 pr-4">{{ $doc->uploader?->name ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $doc->created_at?->diffForHumans() }}</td>
                                    <td class="py-2 pr-4 text-right">
                                        <x-filament::button
                                            color="danger"
                                            size="xs"
                                            icon="heroicon-o-trash"
                                            wire:click="deleteDocument({{ $doc->id }})"
                                            wire:confirm="Remove &quot;{{ $doc->filename }}&quot; from the knowledge base? The chatbot will no longer reference it."
                                        >
                                            Remove
                                        </x-filament::button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
