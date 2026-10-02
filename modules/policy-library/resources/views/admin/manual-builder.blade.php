<x-filament-panels::page>
    <style>
        .pl-builder{--pl-navy:#12344e;--pl-aqua:#087f8c;--pl-line:#dce5eb;color:var(--pl-navy)}
        .pl-builder button,.pl-builder a,.pl-builder select{min-height:44px}.pl-builder button:focus-visible,.pl-builder a:focus-visible,.pl-builder select:focus-visible{outline:3px solid #29b9c1;outline-offset:3px}
        .pl-actions{display:flex;flex-wrap:wrap;gap:.6rem;padding-bottom:1.3rem;border-bottom:1px solid var(--pl-line)}
        .pl-workspace{display:grid;grid-template-columns:310px minmax(0,1fr);margin-top:1.4rem;border:1px solid var(--pl-line);border-radius:12px;overflow:hidden;background:#fff}
        .pl-rail{background:#f5f8fa;border-right:1px solid var(--pl-line);padding:1.2rem;min-width:0}.pl-eyebrow{font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;font-weight:700;color:#526a7c}
        .pl-manuals{display:grid;gap:.25rem;margin:.8rem 0 1.2rem}.pl-manual{display:flex;align-items:center;gap:.3rem;border-radius:7px}.pl-manual.is-selected{background:#e0f0f1;color:#075d67}.pl-manual-select{flex:1;text-align:left;font-weight:650;padding:.5rem .6rem}
        .pl-drag{width:28px;min-width:28px;cursor:grab;color:#758999;display:inline-flex;align-items:center;justify-content:center}.pl-drag:active{cursor:grabbing}.pl-tree{padding-top:.8rem;margin-top:1rem;border-top:1px solid var(--pl-line)}
        .pl-tree-list{list-style:none;margin:0;padding:0}.pl-tree-list .pl-tree-list{padding-left:1rem;border-left:1px solid #d5e1e7;margin-left:.55rem}
        .pl-tree-row{display:flex;align-items:center;gap:.15rem;border-radius:6px;padding:.1rem 0}.pl-tree-row.is-selected{background:#dceef2;box-shadow:inset 3px 0 #087f8c}.pl-tree-select{display:flex;gap:.4rem;align-items:center;flex:1;min-width:0;text-align:left;padding:.4rem .15rem}.pl-tree-title{overflow-wrap:anywhere;font-size:.85rem;line-height:1.4}.pl-tree-icon{width:16px;min-width:16px;color:#557384}.pl-expand{width:24px;min-width:24px}.pl-hidden{font-size:.65rem;color:#526a7c;display:block}.pl-tree-hint{font-size:.75rem;line-height:1.5;color:#5b7180;margin-top:1rem}
        .pl-main{padding:clamp(1.2rem,2vw,2rem);min-width:0}.pl-context{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;padding-bottom:1.2rem;margin-bottom:1.5rem;border-bottom:1px solid var(--pl-line)}.pl-context h2{font-size:clamp(1.2rem,2vw,1.65rem);font-weight:700;line-height:1.3;margin:.35rem 0}.pl-muted{font-size:.8rem;line-height:1.6;color:#586f7f}.pl-status{display:inline-block;white-space:nowrap;background:#e5f2f0;color:#176955;border-radius:4px;font-size:.72rem;padding:.35rem .55rem;font-weight:650}.pl-status.is-draft{background:#edf1f5;color:#536779}
        .pl-editor-layout{display:grid;grid-template-columns:minmax(0,1fr) 260px;gap:2rem}.pl-editor-footer{display:flex;flex-wrap:wrap;align-items:center;gap:.7rem;margin-top:1.2rem}.pl-order{display:flex;flex-wrap:wrap;align-items:center;gap:.35rem;margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--pl-line)}.pl-order button{padding:.35rem .65rem;border:1px solid var(--pl-line);border-radius:6px;font-size:.78rem;background:#fff}.pl-history{border-left:1px solid var(--pl-line);padding-left:1.3rem}.pl-history h3{font-weight:700;font-size:.95rem}.pl-revision{padding:1rem 0;border-bottom:1px solid var(--pl-line)}.pl-revision-title{font-weight:650;font-size:.88rem}.pl-revision-actions{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:.5rem}.pl-revision-actions a,.pl-revision-actions button{display:inline-flex;align-items:center;font-size:.8rem;color:#08717c;font-weight:650}.pl-document-actions{display:flex;flex-wrap:wrap;gap:.5rem;margin:1.2rem 0}.pl-edition-note{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;background:#eef5f8;padding:1rem;margin-bottom:1.4rem;border-radius:7px}
        .pl-select{width:100%;border:1px solid #cad8e1;border-radius:6px;background:#fff;font-size:.8rem;margin-top:.4rem;padding:.5rem}.pl-empty{padding:2rem 0;max-width:40rem}.pl-empty h2{font-size:1.4rem;font-weight:700}.pl-empty p{color:#526a7c;margin:.7rem 0 1.4rem}.pl-imports{margin-top:1.5rem;border-top:1px solid var(--pl-line);padding-top:1rem}.pl-import-row{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.75rem 0;border-bottom:1px solid var(--pl-line)}.pl-import-row button{font-size:.8rem;color:#08717c;font-weight:650}.pl-remove{margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--pl-line);display:flex;gap:1rem;flex-wrap:wrap}.pl-remove .fi-btn{font-size:.75rem}
        .pl-main{max-width:1600px;width:100%}
        @media(min-width:1800px){.pl-workspace{grid-template-columns:350px minmax(0,1fr)}.pl-editor-layout{grid-template-columns:minmax(0,1fr) 320px}.pl-builder{font-size:1.05rem}.pl-tree-title{font-size:1rem}.pl-muted{font-size:.95rem}.pl-actions .fi-btn{font-size:1rem;padding:.75rem 1rem}.pl-builder .fi-fo-field-wrp-label{font-size:1rem}}
        @media(min-width:761px){.pl-rail{display:flex;flex-direction:column;height:calc(100dvh - 275px);min-height:420px}.pl-tree{min-height:0;flex:1;overflow:auto;overscroll-behavior:contain}.pl-manuals{flex-shrink:0}.pl-tree-hint{flex-shrink:0}}
        .pl-select{appearance:none;padding-right:2.25rem;background-repeat:no-repeat;background-position:right .5rem center;background-size:1.5em 1.5em}
        @media(max-width:1100px){.pl-editor-layout{grid-template-columns:1fr}.pl-history{border-left:0;border-top:1px solid var(--pl-line);padding:1.2rem 0 0}.pl-workspace{grid-template-columns:270px minmax(0,1fr)}}
        @media(max-width:760px){.pl-workspace{grid-template-columns:1fr}.pl-rail{border-right:0;border-bottom:1px solid var(--pl-line)}.pl-tree{max-height:320px;overflow:auto}.pl-actions{gap:.45rem}.pl-context{flex-wrap:wrap}.pl-import-row{align-items:flex-start;flex-wrap:wrap}}
        @media(max-width:1100px){.pl-drag,.pl-expand{width:44px;min-width:44px}}
    </style>
    <div class="pl-builder" x-data="{ draggingNode: null, draggingManual: null }" data-testid="manual-builder">
        <div class="pl-actions" aria-label="Manual Builder actions">
            {{ $this->addManualAction }}
            {{ $this->addSectionAction }}
            {{ $this->addSubsectionAction }}
            {{ $this->addDocumentAction }}
            {{ $this->uploadManualAction }}
            {{ $this->importSectionAction }}
            @if ($manual)
                <x-filament::button color="gray" icon="heroicon-o-eye" tag="a" target="_blank" :href="route('policy-library.viewer', array_filter(['manual' => $manual->slug, 'node' => $edition?->id === $manual->active_edition_id ? $selected?->slug : null, 'page' => 1]))">Preview Member View</x-filament::button>
            @endif
        </div>
        <div class="pl-workspace">
            <aside class="pl-rail" aria-label="Manuals and hierarchy">
                <div class="pl-eyebrow">Library manuals</div>
                <div class="pl-manuals">
                    @forelse ($manuals as $item)
                        <div class="pl-manual {{ $manualId === $item->id ? 'is-selected' : '' }}" wire:key="manual-{{ $item->id }}" x-on:dragover.prevent x-on:drop.prevent="if (draggingManual !== null) { $wire.reorderManual(draggingManual, {{ $item->id }}); draggingManual = null; }">
                            <button type="button" class="pl-drag" draggable="true" aria-label="Drag {{ $item->name }} to reorder manuals" x-on:dragstart="draggingManual = {{ $item->id }}; $event.dataTransfer.setData('text/plain', '{{ $item->id }}')" x-on:dragend="draggingManual = null">⠿</button>
                            <button type="button" class="pl-manual-select" wire:click="selectManual({{ $item->id }})" @if ($manualId === $item->id) aria-current="true" @endif>{{ $item->name }}@unless ($item->is_active)<span class="pl-hidden">Archived</span>@endunless</button>
                        </div>
                    @empty
                        <p class="pl-muted">Add the first manual to begin.</p>
                    @endforelse
                </div>
                @if ($manual)
                    <label class="pl-eyebrow" for="builder-edition">Edition</label>
                    <select id="builder-edition" class="pl-select" wire:change="selectEdition($event.target.value)">
                        @foreach ($editions as $item)
                            <option value="{{ $item->id }}" @selected($editionId === $item->id)>{{ $item->label }} · {{ $item->state }}</option>
                        @endforeach
                    </select>
                    <div class="pl-tree" role="tree" aria-label="{{ $manual->name }} structure">
                        <ul class="pl-tree-list">
                            @foreach ($tree->get('', collect()) as $item)
                                @include('policy-library::admin.tree-node', ['item' => $item])
                            @endforeach
                        </ul>
                        @if ($tree->isEmpty())<p class="pl-muted">Create a section, then add its documents.</p>@endif
                    </div>
                    <p class="pl-tree-hint">Drag handles reorder siblings. Select an entry to edit it or move it to another parent section.</p>
                @endif
            </aside>
            <main class="pl-main" aria-label="Selected item editor">
                @if ($manual)
                    <div class="pl-context">
                        <div><div class="pl-eyebrow">{{ $manual->name }} @if ($selected) / {{ $selected->type === 'section' ? 'Section' : 'Document' }} @endif</div><h2>{{ $selected?->title ?? $manual->name }}</h2><p class="pl-muted">{{ $selected ? 'Edit this entry without changing its bookmark.' : 'Manage the manual name, availability and editions.' }}</p></div>
                        <span class="pl-status {{ $edition?->state !== 'published' ? 'is-draft' : '' }}">{{ ! $manual->is_active || ($selected && ! $selected->is_active) ? 'Hidden from members' : ($edition?->state === 'published' ? 'Published edition' : ucfirst($edition?->state ?? 'No edition')) }}</span>
                    </div>
                    @if ($edition && $edition->id !== $manual->active_edition_id)
                        <div class="pl-edition-note"><p class="pl-muted">{{ $edition->state === 'archived' ? 'Historical edition: preserved for review and restoration.' : 'Working edition: documents remain private until this manual edition is published.' }}</p>{{ $this->publishEditionAction }}</div>
                    @endif
                    <div class="pl-editor-layout">
                        <div>
                            <form wire:submit="save">
                                {{ $this->form }}
                                <div class="pl-editor-footer">
                                    <x-filament::button type="submit" :disabled="$selected && $edition?->state === 'archived'" wire:loading.attr="disabled" wire:target="save">Save changes</x-filament::button>
                                    @if ($selected)<button type="button" class="pl-muted" wire:click="selectManual({{ $manual->id }})">Edit manual</button>@endif
                                </div>
                            </form>
                            @if ($selected)
                                <div class="pl-order"><span class="pl-muted">Order within parent</span><button type="button" wire:click="moveSelected(-1)" @disabled($edition?->state === 'archived')>↑ Move up</button><button type="button" wire:click="moveSelected(1)" @disabled($edition?->state === 'archived')>↓ Move down</button></div>
                            @else
                                <div class="pl-order"><span class="pl-muted">Manual order</span><button type="button" wire:click="moveManual(-1)">↑ Move up</button><button type="button" wire:click="moveManual(1)">↓ Move down</button></div>
                            @endif
                            @if ($selected?->type === 'document' && $edition?->state !== 'archived')
                                <div class="pl-document-actions">{{ $this->uploadPdfAction }} {{ $this->replacePagesAction }}</div>
                                <p class="pl-muted">Uploads and page replacements create drafts. Preview before publishing; older PDFs remain immutable.</p>
                            @endif
                            <div class="pl-remove">{{ $this->archiveAction }} @if ($this->deleteDraftAction->isVisible()){{ $this->deleteDraftAction }}@endif</div>
                        </div>
                        <aside class="pl-history" aria-label="Revision history">
                            @if ($selected?->type === 'document')
                                <h3>PDF revisions</h3>
                                @forelse ($revisions as $revision)
                                    <div class="pl-revision" wire:key="revision-{{ $revision->id }}">
                                        <div class="pl-revision-title">{{ $revision->version_label ?: 'Revision '.$revision->id }} <span class="pl-status {{ $revision->id !== $selected->current_revision_id ? 'is-draft' : '' }}">{{ $revision->id === $selected->current_revision_id ? 'Current' : ucfirst($revision->state) }}</span></div>
                                        <p class="pl-muted">{{ $revision->page_count }} {{ Str::plural('page', $revision->page_count) }} · {{ $revision->revision_date?->format('M j, Y') ?? $revision->created_at?->format('M j, Y') }}</p>
                                        @if ($revision->revision_notes)<p class="pl-muted">{{ $revision->revision_notes }}</p>@endif
                                        <div class="pl-revision-actions"><a href="{{ route('policy-library.preview', ['uuid' => $revision->uuid]) }}" target="_blank" rel="noopener">Preview PDF ↗</a>
                                            @if ($revision->id !== $selected->current_revision_id && $edition?->state !== 'archived')<button type="button" wire:click="mountAction('publishRevision', { revision: {{ $revision->id }} })">{{ $revision->state === 'archived' ? 'Roll back' : 'Publish' }}</button>@endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="pl-muted">Upload this document’s first PDF to create a revision.</p>
                                @endforelse
                            @else
                                <h3>{{ $selected ? 'Section contents' : 'Manual editions' }}</h3>
                                <p class="pl-muted">{{ $selected ? 'Add a document or subsection. The tree is the source of member navigation.' : 'Select an edition to review its tree. Uploads prepare a complete draft without replacing the current manual.' }}</p>
                                <p class="pl-muted" style="margin-top:1rem">Stable bookmark: {{ $selected?->slug ?? $manual->slug }}</p>
                            @endif
                        </aside>
                    </div>
                @else
                    <div class="pl-empty"><div class="pl-eyebrow">Your library workspace</div><h2>Start with a manual</h2><p>Add a manual, create its sections and upload the first PDF. You can also upload a complete MOMS or SOG PDF.</p>{{ $this->addManualAction }}</div>
                @endif
                @if ($batches->isNotEmpty())
                    <section class="pl-imports" wire:poll.10s aria-label="Import progress"><div class="pl-eyebrow">Recent imports</div>
                        @foreach ($batches as $batch)
                            <div class="pl-import-row" wire:key="import-{{ $batch->id }}">
                                <div><strong style="font-size:.8rem">{{ $batch->source_filename }}</strong><p class="pl-muted">{{ ucfirst($batch->state) }} · {{ $batch->status_message }}</p></div>
                                <div>
                                    @if ($batch->state === 'ready')
                                        @foreach ($batch->edition_ids ?? [] as $id)
                                            <button type="button" wire:key="import-edition-{{ $batch->id }}-{{ $id }}" wire:click="reviewImport({{ $id }})">Review draft →</button>
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        <a class="pl-muted" href="{{ $importUrl }}" style="display:inline-flex;align-items:center">All imports and retry options →</a>
                    </section>
                @endif
            </main>
        </div>
    </div>
</x-filament-panels::page>
