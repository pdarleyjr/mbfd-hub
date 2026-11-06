@php
    use App\Filament\Resources\ChecklistTemplateResource as Templates;
    /** @var list<array<string, mixed>> $templates */
@endphp
<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-'.str_replace('/', '-', $this->getResource()::getSlug()),
    ])
    data-hub-ui="2"
>
    <div class="flex flex-col gap-y-6">
        <section class="hub-insp" aria-labelledby="tpl-index-title" data-section="templates">
            <h2 class="sr-only" id="tpl-index-title">Checklists</h2>
            @if($overviewError)
                <div class="hub-insp-card hub-insp-card--danger" role="alert"><p class="hub-insp-note">{{ $overviewError }}</p></div>
            @endif
            <div class="hub-tpl-grid">
                @foreach($templates as $template)
                    @php
                        $published = $template['published'] ?? null;
                        $draft = $template['draft'] ?? null;
                        $isPublished = ($template['source'] ?? 'file') === 'published' && $published;
                        $headingId = 'tpl-card-'.$template['type'];
                    @endphp
                    <article class="hub-insp-card hub-tpl-card" aria-labelledby="{{ $headingId }}" data-template-card="{{ $template['type'] }}">
                        <div class="hub-insp-head">
                            <div class="hub-insp-head__main">
                                <h3 class="hub-insp-title" id="{{ $headingId }}">{{ $template['label'] }}</h3>
                                <p class="hub-insp-sub">{{ $template['compartment_count'] }} compartments · {{ $template['item_count'] }} items</p>
                            </div>
                            <div class="hub-insp-badges">
                                <span class="hub-insp-badge {{ $isPublished ? 'hub-insp-badge--success' : 'hub-insp-badge--info' }}" data-template-source="{{ $template['source'] }}">
                                    {{ $isPublished ? 'Published v'.$published['version_number'] : 'Repository file' }}
                                </span>
                                @if($draft)
                                    <span class="hub-insp-badge hub-insp-badge--warning" data-template-draft>{{ ($draft['has_changes'] ?? false) ? 'Unpublished changes' : 'Open draft' }}</span>
                                @endif
                            </div>
                        </div>
                        <dl class="hub-insp-facts hub-insp-facts--plain">
                            <div><dt>Units using it</dt><dd>
                                @forelse($template['units'] ?? [] as $unit)
                                    {{ $unit['label'] }}@if(! $unit['daily_checkout']) <small class="hub-tpl-inline">(reserve)</small>@endif{{ $loop->last ? '' : ',' }}
                                @empty
                                    None
                                @endforelse
                            </dd></div>
                            <div><dt>Last publication</dt><dd>
                                @if($published)
                                    v{{ $published['version_number'] }} · {{ Templates::when($published['published_at'] ?? null) ?? 'date not recorded' }}
                                    <small>{{ $published['published_by'] ?? 'Unknown' }}@if(filled($published['change_summary'] ?? null)) · {{ $published['change_summary'] }}@endif</small>
                                @else
                                    Not published here
                                    <small>Members get the repository file.</small>
                                @endif
                            </dd></div>
                            @if($draft)
                                <div><dt>Open draft</dt><dd>
                                    {{ Templates::when($draft['updated_at'] ?? null) }}
                                    <small>{{ $draft['updated_by'] ?? 'Unknown' }}{{ ($draft['has_changes'] ?? false) ? ' · unpublished changes' : ' · no changes yet' }}</small>
                                </dd></div>
                            @endif
                        </dl>
                        @if($template['file_drift'] ?? false)
                            <p class="hub-tpl-warning" role="status" data-file-drift>The repository file changed after this version was published.</p>
                        @endif
                        @if(filled($template['file_error'] ?? null))
                            <p class="hub-tpl-warning" role="status" data-file-error>Repository file problem: {{ $template['file_error'] }}</p>
                        @endif
                        <div class="hub-tpl-card__foot">
                            <span class="hub-tpl-muted">Schema v{{ $template['schema_version'] ?? 1 }}</span>
                            <a class="hub-insp-btn {{ $canManage ? 'hub-insp-btn--primary' : '' }}" href="{{ Templates::getUrl('editor', ['type' => $template['type']]) }}" aria-label="{{ ($canManage ? 'Edit ' : 'View ').$template['label'] }}">
                                {{ $canManage ? 'Edit' : 'View' }}
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

        {{ $this->table }}

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
    </div>
</x-filament-panels::page>
