{{-- One input of a dashboard action. Expects: $field, $fieldId, $errorKey, $error, $model. --}}
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
