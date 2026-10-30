{{--
    Action panel: right drawer on desktop, full-height sheet on phones. Opens on the
    `dashboard-issue-open` browser event from a row button; focus moves into the dialog, Escape
    closes it and focus returns to the row. Content appears once this open's fresh detail() has
    loaded. Only the actions the source offers are rendered; every value is escaped.
--}}
@php
    $d = $detail;
    $hasDetail = $d !== [] ;
@endphp
<div class="mbfd-dash-panel-host"
    x-data="{
        shown: false,
        openedAt: 0,
        returnEl: null,
        returnRef: null,
        get ready() { return this.$wire.loads > this.openedAt; },
        openFrom(event) {
            const origin = event.target && event.target.closest ? event.target.closest('[data-issue-ref]') : null;
            this.returnEl = origin;
            this.returnRef = origin ? origin.dataset.issueRef : null;
            this.openedAt = this.$wire.loads;
            this.shown = true;
            this.$nextTick(() => this.$refs.dialog && this.$refs.dialog.focus());
        },
        close() {
            if (! this.shown) return;
            this.shown = false;
            this.$wire.close();
            let target = this.returnEl && document.contains(this.returnEl) ? this.returnEl : null;
            if (! target && this.returnRef) target = document.querySelector('[data-issue-ref=&quot;' + CSS.escape(this.returnRef) + '&quot;]');
            this.$nextTick(() => target && target.focus());
        },
    }"
    x-on:dashboard-issue-open.window="openFrom($event)"
    x-on:keydown.escape.window="close()">

    <div class="mbfd-dash-panel-backdrop" x-show="shown" x-cloak x-on:click="close()" aria-hidden="true"
        x-transition:enter="mbfd-dash-fade" x-transition:leave="mbfd-dash-fade"></div>

    <section class="mbfd-dash-panel" x-show="shown" x-cloak x-ref="dialog" tabindex="-1"
        x-trap.inert.noscroll="shown"
        role="dialog" aria-modal="true" aria-labelledby="mbfd-dash-panel-title"
        data-issue-panel>
        <header class="mbfd-dash-panel__head">
            <div class="mbfd-dash-panel__heading">
                <p class="mbfd-dash-panel__eyebrow" x-show="ready">{{ $d['typeLabel'] ?? 'Item' }}</p>
                <h2 id="mbfd-dash-panel-title" class="mbfd-dash-panel__title">
                    <span x-show="! ready">Loading item…</span>
                    <span x-show="ready">{{ $hasDetail ? ($d['subject'] ? $d['subject'].' — ' : '').$d['title'] : 'Item details' }}</span>
                </h2>
            </div>
            <button type="button" class="mbfd-dash-panel__close" x-on:click="close()" aria-label="Close panel">
                <x-filament::icon icon="heroicon-m-x-mark" class="mbfd-dash-panel__close-icon" />
            </button>
        </header>

        <div class="mbfd-dash-panel__body">
            <p class="mbfd-dash-panel__loading" x-show="! ready" role="status">Loading the latest details…</p>

            <div x-show="ready" x-cloak>
                <div role="status" aria-live="polite" class="mbfd-dash-panel__live">
                    @if ($notice)
                        <p class="mbfd-dash-panel__notice" data-tone="{{ $notice['tone'] }}" data-panel-notice>{{ $notice['text'] }}</p>
                    @endif
                </div>

                @if ($hasDetail)
                    <div class="mbfd-dash-panel__summary">
                        @if ($d['image'])
                            <span class="mbfd-dash-thumb mbfd-dash-thumb--lg mbfd-dash-thumb--{{ $d['imageKind'] }}">
                                <img src="{{ $d['image'] }}" @if ($d['image2x']) srcset="{{ $d['image'] }} 1x, {{ $d['image2x'] }} 2x" @endif
                                    width="96" height="64" loading="lazy" decoding="async" alt="{{ $d['subject'] ?? $d['title'] }}">
                            </span>
                        @else
                            <span class="mbfd-dash-thumb mbfd-dash-thumb--lg mbfd-dash-thumb--icon" aria-hidden="true">
                                <x-filament::icon :icon="$d['icon']" class="mbfd-dash-thumb__icon" />
                            </span>
                        @endif
                        <dl class="mbfd-dash-panel__keyfacts">
                            <div><dt>Severity</dt><dd><span class="mbfd-dash-badge" data-severity="{{ $d['severity'] }}">{{ $d['severityLabel'] }}</span></dd></div>
                            <div><dt>Status</dt><dd>{{ $d['statusLabel'] }}</dd></div>
                            <div><dt>Reported</dt><dd><time datetime="{{ $d['occurredIso'] }}">{{ $d['occurred'] }}</time></dd></div>
                            @if (filled($d['reporter']))
                                <div><dt>Reported by</dt><dd>{{ $d['reporter'] }}</dd></div>
                            @endif
                            @if (filled($d['category']))
                                <div><dt>Category</dt><dd>{{ $d['category'] }}</dd></div>
                            @endif
                        </dl>
                    </div>

                    @if (filled($d['summary']))
                        <p class="mbfd-dash-panel__lead">{{ $d['summary'] }}</p>
                    @endif

                    @if (filled($d['body']) && $d['body'] !== $d['summary'])
                        <div class="mbfd-dash-panel__text" data-panel-body>{{ $d['body'] }}</div>
                    @endif

                    @if ($d['facts'] !== [])
                        <dl class="mbfd-dash-panel__facts">
                            @foreach ($d['facts'] as $fact)
                                <div><dt>{{ $fact['label'] }}</dt><dd>{{ $fact['value'] }}</dd></div>
                            @endforeach
                        </dl>
                    @endif

                    @if ($d['timeline'] !== [])
                        <h3 class="mbfd-dash-panel__subhead">Timeline</h3>
                        <ol class="mbfd-dash-panel__timeline">
                            @foreach ($d['timeline'] as $entry)
                                <li>
                                    <time datetime="{{ $entry['atIso'] }}">{{ $entry['at'] }}</time>
                                    @if (filled($entry['actor']))<span class="mbfd-dash-panel__actor">{{ $entry['actor'] }}</span>@endif
                                    <p>{{ $entry['text'] }}</p>
                                </li>
                            @endforeach
                        </ol>
                    @endif

                    @if ($d['readOnlyReason'])
                        <p class="mbfd-dash-panel__readonly" data-panel-readonly>{{ $d['readOnlyReason'] }}</p>
                    @endif

                    @foreach ($d['actions'] as $action)
                        <form class="mbfd-dash-action" wire:key="action-{{ $action['key'] }}-{{ $loads }}"
                            wire:submit="submit('{{ $action['key'] }}', '{{ $token }}')"
                            @if (filled($action['confirm'])) wire:confirm="{{ $action['confirm'] }}" @endif
                            data-panel-action="{{ $action['key'] }}">
                            @if ($action['fields'] !== [])
                                <fieldset class="mbfd-dash-action__fields">
                                    <legend class="mbfd-dash-action__legend">{{ $action['label'] }}</legend>
                                    @foreach ($action['fields'] as $field)
                                        @php
                                            $fieldId = 'mbfd-dash-'.$action['key'].'-'.$field['name'];
                                            $errorKey = $action['key'].'.'.$field['name'];
                                            $error = $fieldErrors[$errorKey] ?? null;
                                            $model = 'input.'.$action['key'].'.'.$field['name'];
                                        @endphp
                                        <div class="mbfd-dash-field">
                                            <label for="{{ $fieldId }}" class="mbfd-dash-field__label">
                                                {{ $field['label'] }}
                                                @if ($field['required'])<span class="mbfd-dash-field__req" aria-hidden="true">*</span><span class="sr-only"> (required)</span>@endif
                                            </label>
                                            @if ($field['type'] === 'textarea')
                                                <textarea id="{{ $fieldId }}" wire:model="{{ $model }}" rows="3"
                                                    @if ($field['max']) maxlength="{{ $field['max'] }}" @endif
                                                    @if ($field['required']) required aria-required="true" @endif
                                                    @if ($error) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif
                                                    class="mbfd-dash-field__control"></textarea>
                                            @elseif ($field['type'] === 'select')
                                                <select id="{{ $fieldId }}" wire:model="{{ $model }}"
                                                    @if ($field['required']) required aria-required="true" @endif
                                                    @if ($error) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif
                                                    class="mbfd-dash-field__control">
                                                    <option value="">Choose…</option>
                                                    @foreach ($field['options'] as $option)
                                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <input id="{{ $fieldId }}" type="{{ $field['type'] === 'date' ? 'date' : 'text' }}" wire:model="{{ $model }}"
                                                    @if ($field['max'] && $field['type'] === 'text') maxlength="{{ $field['max'] }}" @endif
                                                    @if ($field['required']) required aria-required="true" @endif
                                                    @if ($error) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif
                                                    class="mbfd-dash-field__control">
                                            @endif
                                            @if ($field['max'] && $field['type'] !== 'select' && $field['type'] !== 'date')
                                                <p class="mbfd-dash-field__hint">Up to {{ number_format($field['max']) }} characters.</p>
                                            @endif
                                            @if ($error)
                                                <p id="{{ $fieldId }}-error" class="mbfd-dash-field__error" data-field-error="{{ $errorKey }}">{{ $error }}</p>
                                            @endif
                                        </div>
                                    @endforeach
                                </fieldset>
                            @endif
                            <button type="submit" class="mbfd-dash-action__submit" data-tone="{{ $action['tone'] }}"
                                wire:loading.attr="disabled" wire:target="submit">
                                <span wire:loading.remove wire:target="submit">{{ $action['label'] }}</span>
                                <span wire:loading wire:target="submit">Saving…</span>
                            </button>
                        </form>
                    @endforeach

                    @if (($d['gone'] ?? false) || ($d['actions'] === [] && $input !== []))
                        @foreach ($input as $actionKey => $values)
                            @php($typed = array_filter(is_array($values) ? $values : [], static fn ($v): bool => is_string($v) && trim($v) !== ''))
                            @if ($typed !== [])
                                <div class="mbfd-dash-panel__unsent">
                                    <p>Not sent. Your text is kept here so you can copy it:</p>
                                    @foreach ($typed as $text)
                                        <textarea readonly rows="2" class="mbfd-dash-field__control" aria-label="Unsent text">{{ $text }}</textarea>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    @endif

                    @if ($d['sourceUrl'])
                        <p class="mbfd-dash-panel__source"><a href="{{ $d['sourceUrl'] }}">Open full record</a></p>
                    @endif
                @endif
            </div>
        </div>
    </section>
</div>
