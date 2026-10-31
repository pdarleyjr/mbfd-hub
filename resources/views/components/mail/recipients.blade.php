@props(['field', 'label', 'draft'])
{{--
    One address field of the composer: chips plus a text box with authorized suggestions from the Hub's own
    directory (MailRecipientDirectory). The chip list is the Livewire property named by $field, kept deferred, so
    typing never makes a request; the debounced autosave carries it.
--}}
<div
    class="mail-field mail-recipients"
    x-data="mbfdMailRecipients({ field: '{{ $field }}', state: $wire.$entangle('{{ $field }}') })"
    x-on:keydown.escape="close()"
    x-on:click.outside="close()"
>
    <label for="mail-{{ $field }}-{{ $draft }}" class="mail-label">{{ $label }}</label>
    <div class="mail-recipients__box" x-on:click="$refs.input.focus()">
        <template x-for="(chip, index) in chips" :key="chip + index">
            <span class="mail-chip" :class="{ 'mail-chip--invalid': !isValid(chip) }">
                <span class="mail-chip__text" x-text="chip"></span>
                <span class="mail-sr" x-show="!isValid(chip)"> (not a valid address)</span>
                <button type="button" class="mail-chip__x" x-on:click.stop="remove(index)" :aria-label="'Remove ' + chip">&times;</button>
            </span>
        </template>
        <input
            id="mail-{{ $field }}-{{ $draft }}"
            x-ref="input"
            x-model="text"
            type="text"
            class="mail-recipients__input"
            inputmode="email"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            role="combobox"
            aria-autocomplete="list"
            aria-haspopup="listbox"
            :aria-expanded="open ? 'true' : 'false'"
            aria-controls="mail-{{ $field }}-list-{{ $draft }}"
            :aria-activedescendant="active >= 0 ? 'mail-{{ $field }}-opt-{{ $draft }}-' + active : null"
            x-on:input="onInput()"
            x-on:keydown="onKey($event)"
            x-on:paste="onPaste($event)"
            x-on:blur="commit()"
        >
    </div>
    <ul
        id="mail-{{ $field }}-list-{{ $draft }}"
        class="mail-suggest"
        role="listbox"
        aria-label="{{ $label }} suggestions"
        x-show="open"
        x-cloak
    >
        <template x-for="(option, index) in options" :key="option.address">
            <li
                role="option"
                :id="'mail-{{ $field }}-opt-{{ $draft }}-' + index"
                class="mail-suggest__item"
                :aria-selected="active === index ? 'true' : 'false'"
                x-on:mousedown.prevent="pick(option)"
            >
                <span class="mail-suggest__name" x-text="option.name || option.address"></span>
                <span class="mail-muted mail-suggest__address" x-show="option.name" x-text="option.address"></span>
            </li>
        </template>
    </ul>
</div>
