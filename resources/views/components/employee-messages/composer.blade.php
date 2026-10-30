@props([
    'method',
    'label' => 'Message',
    'placeholder' => 'Type your message',
    'submitLabel' => 'Send',
    'withSubject' => false,
    'autofocus' => false,
    'idPrefix' => 'emsg',
    'hint' => 'Text only. Support Services will reply here.',
    'withReason' => false,
    'threadId' => null,
])
@php
    $max = \App\Services\EmployeeMessaging\EmployeeLogisticsMessagingService::MAX_BODY_LENGTH;
    $maxSubject = \App\Services\EmployeeMessaging\EmployeeLogisticsMessagingService::MAX_SUBJECT_LENGTH;
    $bodyId = $idPrefix.'-body';
    $subjectId = $idPrefix.'-subject';
    $hintId = $idPrefix.'-hint';
    $feedbackId = $idPrefix.'-feedback';
    $reasonId = $idPrefix.'-reason';
@endphp
<form
    wire:ignore
    x-data="window.mbfdEmployeeMessageComposer({ method: @js($method), withSubject: @js((bool) $withSubject), withReason: @js((bool) $withReason), autofocus: @js((bool) $autofocus), maxLength: {{ $max }}, threadId: @js($threadId === null ? null : (int) $threadId) })"
    x-on:submit.prevent="send()"
    data-employee-message-composer
    {{ $attributes->class('emsg-composer') }}
    novalidate
>
    @if ($withReason)
        <div class="emsg-field">
            <label for="{{ $reasonId }}" class="emsg-label">Reason</label>
            <select
                id="{{ $reasonId }}"
                x-model="reason"
                class="emsg-input emsg-select"
                x-bind:disabled="sending"
            >
                @foreach (\App\Services\EmployeeMessaging\EmployeeMessageReason::options() as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if ($withSubject)
        <div class="emsg-field">
            <label for="{{ $subjectId }}" class="emsg-label">Subject <span class="emsg-optional">(optional)</span></label>
            <input
                id="{{ $subjectId }}"
                type="text"
                x-model="subject"
                maxlength="{{ $maxSubject }}"
                autocomplete="off"
                class="emsg-input"
                x-bind:readonly="sending"
            >
        </div>
    @endif

    <div class="emsg-field">
        <label for="{{ $bodyId }}" class="emsg-label">{{ $label }}</label>
        <textarea
            id="{{ $bodyId }}"
            x-ref="body"
            x-model="body"
            x-on:keydown="onKeydown($event)"
            rows="4"
            maxlength="{{ $max }}"
            placeholder="{{ $placeholder }}"
            class="emsg-textarea"
            aria-describedby="{{ $hintId }} {{ $feedbackId }}"
            x-bind:aria-invalid="error !== '' ? 'true' : 'false'"
            x-bind:readonly="sending"
        ></textarea>
    </div>

    <div class="emsg-composer__footer">
        <p id="{{ $hintId }}" class="emsg-hint">
            <span x-show="remaining < 500" x-cloak x-text="remaining.toLocaleString() + ' characters left'"></span>
            <span x-show="remaining >= 500">{{ $hint }}</span>
        </p>
        <button
            type="submit"
            class="emsg-send"
            x-bind:disabled="sending"
            x-bind:aria-busy="sending ? 'true' : 'false'"
        >
            <svg x-show="sending" x-cloak class="emsg-spinner" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            <svg x-show="!sending" class="emsg-send__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>
            <span x-text="sending ? 'Sending…' : @js($submitLabel)">{{ $submitLabel }}</span>
        </button>
    </div>

    <div id="{{ $feedbackId }}" class="emsg-feedback" aria-live="polite" aria-atomic="true">
        <p x-show="error !== ''" x-cloak class="emsg-feedback__error" role="alert" x-text="error"></p>
        <p x-show="error === '' && status !== ''" x-cloak class="emsg-feedback__status" x-text="status"></p>
    </div>
</form>
