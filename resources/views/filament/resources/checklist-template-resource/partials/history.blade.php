@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\DailyCheckoutChecklistTemplateVersion> $history */
@endphp
<section class="hub-insp-card" aria-labelledby="tpl-history-title" data-section="history">
    <h2 class="hub-insp-card__title" id="tpl-history-title">Version history</h2>
    @if($history->isEmpty())
        <p class="hub-insp-note">Nothing has been published from this page yet. Members get the repository file.</p>
    @else
        <ul class="hub-insp-history">
            @foreach($history as $version)
                @php
                    $isLive = $version->status === 'published';
                    $publisher = \App\Filament\Resources\ChecklistTemplateResource::personName($version->publishedBy);
                @endphp
                <li class="hub-insp-history__row" data-template-version="{{ $version->version_number }}">
                    <div class="hub-insp-history__main">
                        <p class="hub-insp-history__title">
                            v{{ $version->version_number }}
                            <span class="hub-insp-badge {{ $isLive ? 'hub-insp-badge--success' : '' }}">{{ $isLive ? 'Live' : (\App\Filament\Resources\ChecklistTemplateResource::STATUS_LABELS[$version->status] ?? $version->status) }}</span>
                        </p>
                        <p class="hub-insp-sub">
                            Published {{ \App\Filament\Resources\ChecklistTemplateResource::when($version->published_at) ?? 'date not recorded' }}@if($publisher) by {{ $publisher }}@endif
                            @if($version->retired_at) · replaced {{ \App\Filament\Resources\ChecklistTemplateResource::when($version->retired_at) }}@endif
                        </p>
                        @if(filled($version->change_summary))
                            <p class="hub-insp-item__text">{{ $version->change_summary }}</p>
                        @endif
                    </div>
                    <div class="hub-insp-actions">
                        @if(! $loop->last)
                            {{ ($this->compareVersionAction)(['version' => $version->getKey()]) }}
                        @endif
                        {{ ($this->viewJsonAction)(['version' => $version->getKey()]) }}
                        @if($canManage)
                            {{ ($this->restoreVersionAction)(['version' => $version->getKey()]) }}
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
