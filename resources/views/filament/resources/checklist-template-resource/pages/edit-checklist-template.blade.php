@php
    /** @var \App\Filament\Resources\ChecklistTemplateResource\Pages\EditChecklistTemplate $this */
    $summary = $this->summary;
    $published = $summary['published'] ?? null;
    $draftInfo = $summary['draft'] ?? null;
    $units = $summary['units'] ?? [];
@endphp
<x-filament-panels::page data-hub-ui="2" data-checklist-template="{{ $this->type }}">
    <div class="hub-insp hub-insp-stack hub-tpl">
        <div class="hub-tpl-callout" role="note" aria-labelledby="tpl-how-title">
            <h2 id="tpl-how-title">How template changes work</h2>
            <ul>
                <li>Edits are saved as a draft. Members see nothing until the draft is published.</li>
                <li>Item and compartment ids are kept, so issue history, inventory corrections and the Captain 5 medical scope keep working after a rename or a move.</li>
                <li>To replace equipment with different equipment, remove the old item and add a new one rather than renaming it.</li>
            </ul>
        </div>

        @if($this->loadError)
            <section class="hub-insp-card hub-insp-card--danger" role="alert" data-section="load-error">
                <h2 class="hub-insp-card__title">This checklist cannot be loaded</h2>
                <p class="hub-insp-note">{{ $this->loadError }}</p>
            </section>
        @endif

        <section class="hub-insp-card" aria-labelledby="tpl-status-title" data-section="status">
            <div class="hub-insp-head">
                <div class="hub-insp-head__main">
                    <h2 class="hub-insp-title" id="tpl-status-title">{{ $this->draftId ? 'Draft' : 'Live checklist' }}</h2>
                    <p class="hub-insp-sub">
                        @if($draftInfo)
                            Draft saved {{ $draftInfo['updated_at'] ?? '' }}@if(filled($draftInfo['updated_by'] ?? null)) by {{ $draftInfo['updated_by'] }}@endif.
                            {{ ($draftInfo['has_changes'] ?? false) ? 'It has unpublished changes.' : 'It is the same as the live checklist.' }}
                        @else
                            No open draft.
                        @endif
                    </p>
                </div>
                <span class="hub-insp-badge hub-insp-badge--lg {{ $this->source === 'published' ? 'hub-insp-badge--success' : 'hub-insp-badge--info' }}" data-template-source="{{ $this->source }}">
                    {{ $this->source === 'published' && $published ? 'Published v'.$published['version_number'] : 'Repository file' }}
                </span>
            </div>
            <dl class="hub-insp-facts">
                <div><dt>Compartments</dt><dd>{{ $summary['compartment_count'] ?? '—' }}</dd></div>
                <div><dt>Items</dt><dd>{{ $summary['item_count'] ?? '—' }}</dd></div>
                <div><dt>Last publication</dt><dd>
                    @if($published)
                        v{{ $published['version_number'] }} · {{ $published['published_at'] }}
                        <small>{{ $published['published_by'] ?? 'Unknown' }}@if(filled($published['change_summary'] ?? null)) · {{ $published['change_summary'] }}@endif</small>
                    @else
                        Never published here
                        <small>Members get the repository file.</small>
                    @endif
                </dd></div>
                <div><dt>Units using it</dt><dd data-template-units>
                    @forelse($units as $unit)
                        {{ $unit['label'] }}@if(! $unit['daily_checkout']) <small class="hub-tpl-inline">(reserve, not on Daily Checkout)</small>@endif{{ $loop->last ? '' : ',' }}
                    @empty
                        None
                    @endforelse
                </dd></div>
            </dl>
            @if($summary['file_drift'] ?? false)
                <p class="hub-tpl-warning" role="status">The repository file changed after this version was published. Members still get the published version; compare the file before reverting.</p>
            @endif
            @if(filled($summary['file_error'] ?? null))
                <p class="hub-tpl-warning" role="status">The repository file has a problem: {{ $summary['file_error'] }}</p>
            @endif
        </section>

        @if(! $canManage)
            <p class="hub-insp-note" data-read-only>You can view this checklist, its history and export it. Editing and publishing need a Logistics or administrator role.</p>
        @endif

        <section aria-labelledby="tpl-form-title" data-section="editor">
            <h2 class="hub-insp-section__title" id="tpl-form-title">Compartments and items</h2>
            <p class="hub-insp-note">Open a compartment to edit its items. Drag or use the arrows to change the order. Deleting a compartment also removes its items, so move any you want to keep first.</p>
            <x-filament-panels::form id="checklist-template-form" wire:submit="saveDraft">
                {{ $this->form }}
            </x-filament-panels::form>
        </section>

        @include('filament.resources.checklist-template-resource.partials.history', ['history' => $history, 'canManage' => $canManage])

        <section class="hub-insp-card" aria-labelledby="tpl-activity-title" data-section="activity">
            <h2 class="hub-insp-card__title" id="tpl-activity-title">Activity</h2>
            @if($events->isEmpty())
                <p class="hub-insp-note">No activity yet.</p>
            @else
                <ol class="hub-insp-timeline">
                    @foreach($events as $event)
                        <li class="hub-insp-timeline__item" data-template-event="{{ $event->action }}">
                            <div class="hub-insp-timeline__head">
                                <p class="hub-insp-timeline__title">{{ $eventLabels[$event->action] ?? \Illuminate\Support\Str::headline((string) $event->action) }}</p>
                            </div>
                            <p class="hub-insp-sub">{{ \App\Filament\Resources\ChecklistTemplateResource::personName($event->user) ?? 'System' }} · {{ \App\Filament\Resources\ChecklistTemplateResource::when($event->created_at) }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </div>
</x-filament-panels::page>
