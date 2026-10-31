{{--
    All of one apparatus, station or member's issues. Each kind of record is a section with its own
    actions, which go to the selected issues; a row opens the single-issue action panel for anything else.
--}}
@php
    $openCount = collect($sections)->sum(fn (array $section): int => count(array_filter($section['rows'], static fn (array $row): bool => $row['open'])));
    $reviewCount = collect($sections)->sum(fn (array $section): int => count(array_filter($section['rows'], static fn (array $row): bool => $row['stage'] === 'awaiting_response')));
@endphp
<div class="mbfd-dash-group" data-issue-group="{{ $group }}" wire:poll.30s.visible="refreshIfChanged">
    <header class="mbfd-dash-group__head">
        <div class="mbfd-dash-group__identity">
            @if ($identity['image'])
                <span class="mbfd-dash-thumb mbfd-dash-thumb--{{ $identity['imageKind'] }}">
                    <img src="{{ $identity['image'] }}" @if ($identity['image2x']) srcset="{{ $identity['image'] }} 1x, {{ $identity['image2x'] }} 2x" @endif width="96" height="64" alt="">
                </span>
            @else
                <span class="mbfd-dash-thumb mbfd-dash-thumb--icon" aria-hidden="true"><x-filament::icon :icon="$identity['icon']" class="mbfd-dash-thumb__icon" /></span>
            @endif
            <div class="mbfd-dash-group__titles">
                <h1 class="mbfd-dash-group__title">{{ $heading !== '' ? $heading : 'Nothing open' }}</h1>
                <p class="mbfd-dash-group__counts">{{ $openCount }} open · {{ $reviewCount }} need review</p>
            </div>
        </div>
    </header>

    <div role="status" aria-live="polite">
        @if ($notice)
            <p class="mbfd-dash-panel__notice" data-tone="{{ $notice['tone'] }}" data-group-notice>{{ $notice['text'] }}</p>
        @endif
    </div>

    @if ($warning)
        <p class="mbfd-dash-notice" role="status" data-group-warning>{{ $warning }}</p>
    @endif

    @if ($sections === [] && ! $warning)
        <p class="mbfd-dash-empty">Nothing is open here now. Everything may have been handled already.</p>
    @endif

    <div class="mbfd-dash-group__sections">
        @foreach ($sections as $section)
            @php
                $openRefs = array_column(array_filter($section['rows'], static fn (array $row): bool => $row['open']), 'ref');
                $selectedHere = array_values(array_intersect($openRefs, $selectedRefs));
                $allSelected = $openRefs !== [] && count($selectedHere) === count($openRefs);
            @endphp
            <section class="mbfd-dash-card mbfd-dash-group__section" wire:key="section-{{ $section['type'] }}" data-group-section="{{ $section['type'] }}"
                aria-labelledby="group-{{ $section['type'] }}" x-data="{ form: null }">
                <header class="mbfd-dash-card__head">
                    <div class="mbfd-dash-card__titles">
                        <h2 id="group-{{ $section['type'] }}" class="mbfd-dash-card__title">{{ $section['label'] }} ({{ count($section['rows']) }})</h2>
                    </div>
                    @if ($openRefs !== [])
                        <button type="button" class="mbfd-dash-group__select-all" wire:click="toggleSection('{{ $section['type'] }}')" aria-pressed="{{ $allSelected ? 'true' : 'false' }}">
                            {{ $allSelected ? 'Clear selection' : 'Select all '.count($openRefs) }}
                        </button>
                    @endif
                </header>

                @if ($section['actions'] !== [] && $openRefs !== [])
                    <div class="mbfd-dash-group__bar" role="group" aria-label="Actions for the selected {{ strtolower($section['label']) }}">
                        @foreach ($section['actions'] as $action)
                            @php $applies = count(array_intersect($action["refs"], $selectedRefs)); @endphp
                            @if ($action['bulk'] === 'direct')
                                <button type="button" class="mbfd-dash-action__submit" data-tone="{{ $action['tone'] }}" data-bulk-action="{{ $action['key'] }}"
                                    wire:click="apply('{{ $section['type'] }}', '{{ $action['key'] }}', '{{ $token }}', false)"
                                    wire:loading.attr="disabled" wire:target="apply" @disabled($applies === 0)>{{ $action['label'] }} {{ $applies }}</button>
                                @if ($action['fields'] !== [])
                                    <button type="button" class="mbfd-dash-group__more" x-on:click="form = form === '{{ $action['key'] }}' ? null : '{{ $action['key'] }}'"
                                        x-bind:aria-expanded="form === '{{ $action['key'] }}'">With a message</button>
                                @endif
                            @else
                                <button type="button" class="mbfd-dash-group__more" data-bulk-open="{{ $action['key'] }}" data-tone="{{ $action['tone'] }}" x-on:click="form = form === '{{ $action['key'] }}' ? null : '{{ $action['key'] }}'"
                                    x-bind:aria-expanded="form === '{{ $action['key'] }}'" @disabled($applies === 0)>{{ $action['label'] }}</button>
                            @endif
                        @endforeach
                    </div>

                    @foreach ($section['actions'] as $action)
                        @if ($action['fields'] !== [])
                            @php $applies = count(array_intersect($action["refs"], $selectedRefs)); @endphp
                            <form class="mbfd-dash-group__form" x-show="form === '{{ $action['key'] }}'" x-cloak wire:key="form-{{ $section['type'] }}-{{ $action['key'] }}"
                                wire:submit="apply('{{ $section['type'] }}', '{{ $action['key'] }}', '{{ $token }}')"
                                @if (filled($action['confirm'])) wire:confirm="This applies to all {{ $applies }} selected. {{ $action['confirm'] }}" @endif
                                data-bulk-form="{{ $action['key'] }}">
                                <fieldset class="mbfd-dash-action__fields">
                                    <legend class="mbfd-dash-action__legend">{{ $action['label'] }}, {{ $applies }} selected</legend>
                                    @foreach ($action['fields'] as $field)
                                        @php
                                            $fieldId = 'mbfd-group-'.$section['type'].'-'.$action['key'].'-'.$field['name'];
                                            $errorKey = $section['type'].'.'.$action['key'].'.'.$field['name'];
                                        @endphp
                                        @include('filament.dashboards.partials.action-field', [
                                            'field' => $field,
                                            'fieldId' => $fieldId,
                                            'errorKey' => $errorKey,
                                            'error' => $fieldErrors[$errorKey] ?? null,
                                            'model' => 'input.'.$section['type'].'.'.$action['key'].'.'.$field['name'],
                                        ])
                                    @endforeach
                                </fieldset>
                                <button type="submit" class="mbfd-dash-action__submit" data-tone="{{ $action['tone'] }}" wire:loading.attr="disabled" wire:target="apply" @disabled($applies === 0)>
                                    <span wire:loading.remove wire:target="apply">{{ $action['label'] }} {{ $applies }}</span>
                                    <span wire:loading wire:target="apply">Saving…</span>
                                </button>
                            </form>
                        @endif
                    @endforeach
                @endif

                <ul class="mbfd-dash-list" role="list" aria-labelledby="group-{{ $section['type'] }}">
                    @foreach ($section['rows'] as $row)
                        <li class="mbfd-dash-group__row" wire:key="group-row-{{ $row['ref'] }}">
                            @if ($row['open'])
                                <label class="mbfd-dash-group__check">
                                    <input type="checkbox" value="{{ $row['ref'] }}" wire:model.live="selected">
                                    <span class="sr-only">Select {{ $row['title'] }}</span>
                                </label>
                            @else
                                <span class="mbfd-dash-group__check" aria-hidden="true"></span>
                            @endif
                            <button type="button" class="mbfd-dash-group__open" data-issue-ref="{{ $row['ref'] }}" aria-haspopup="dialog"
                                x-on:click="$dispatch('dashboard-issue-open', { ref: $el.dataset.issueRef })">
                                <span class="mbfd-dash-group__line">
                                    <span class="mbfd-dash-group__name">{{ $row['title'] }}</span>
                                    <span class="mbfd-dash-badge" data-severity="{{ $row['severity'] }}">{{ $row['severityLabel'] }}<span class="sr-only"> priority</span></span>
                                </span>
                                <span class="mbfd-dash-group__sub">
                                    {{ $row['statusLabel'] }}@if (filled($row['summary'])) · {{ $row['summary'] }}@endif @if (filled($row['actor'])) · {{ $row['actor'] }}@endif · <time datetime="{{ $row['timeIso'] }}">{{ $row['time'] }}</time>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</div>
