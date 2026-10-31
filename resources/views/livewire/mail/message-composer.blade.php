@php
    use App\Support\Mail\MailPresentation as Words;

    $status = $result['status'] ?? null;
    $guarded = ! $locked && $openError === null;
    $accepted = $status === 'accepted';
    $mime = collect((array) config('communications.allowed_attachment_mime_types', []))->implode(',');
@endphp
<section
    class="mail-compose"
    data-mail-compose
    data-draft="{{ $draftId }}"
    @if ($guarded) data-mbfd-unsaved-work-guard @endif
    aria-labelledby="mail-compose-title"
    x-data="mbfdMailComposer({ draftId: {{ (int) $draftId }}, limit: {{ (int) $recipientLimit }}, locked: @js(! $guarded), showCc: @js(count($cc) > 0), showBcc: @js(count($bcc) > 0), focus: @js($mode === 'new' ? 'to' : 'body') })"
    x-on:mail-touch="touch($event)"
    x-on:trix-change="touch($event)"
>
    <header class="mail-compose__header">
        <button type="button" class="mail-btn mail-btn--ghost mail-back" wire:click="close" data-mail-back>
            <x-filament::icon icon="heroicon-m-arrow-left" class="mail-icon" />
            Back
        </button>
        <h2 class="mail-compose__title" id="mail-compose-title">{{ $heading }}</h2>
        @if ($guarded)
            <p class="mail-compose__status" role="status" aria-live="polite" data-mail-save-status x-text="statusText"></p>
        @endif
        @if ($guarded)
            <button type="button" class="mail-btn mail-btn--secondary mail-compose__close" wire:click="close" data-mail-close>Save and close</button>
        @endif
    </header>

    @if ($openError !== null)
        <div class="mail-state" role="alert">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mail-state__icon" />
            <p class="mail-state__title">This message could not be opened</p>
            <p class="mail-muted">{{ $openError }}</p>
            <button type="button" class="mail-btn mail-btn--secondary" wire:click="close">Go back</button>
        </div>
    @elseif ($accepted)
        <div class="mail-result mail-result--ok" role="status">
            <x-filament::icon icon="heroicon-o-check-circle" class="mail-state__icon" />
            <p class="mail-state__title">Sent</p>
            <p>{{ $result['message'] }}</p>
            <p class="mail-muted">The sending service accepted your message. Whether each person's server accepts it is shown under the message in Sent, and it can take a few minutes to appear.</p>
            <div class="mail-actions">
                @if ($result['thread'])
                    <button type="button" class="mail-btn mail-btn--primary" wire:click="finish(true)">View in Sent</button>
                @endif
                <button type="button" class="mail-btn mail-btn--secondary" wire:click="finish(false)">Done</button>
            </div>
        </div>
    @else
        @if ($conflict)
            <div class="mail-callout mail-callout--error" role="alert">
                <p><strong>This draft was changed somewhere else.</strong> You may have it open in another window or on another device. Nothing has been lost yet. Choose which version to keep.</p>
                <div class="mail-actions">
                    <button type="button" class="mail-btn mail-btn--secondary" wire:click="useSavedVersion">Use the other version</button>
                    <button type="button" class="mail-btn mail-btn--primary" wire:click="keepMyVersion">Keep what I typed here</button>
                </div>
            </div>
        @endif

        @if ($saveError)
            <p class="mail-callout mail-callout--error" role="alert">{{ $saveError }}</p>
        @endif

        @if ($status === 'refused')
            <div class="mail-callout mail-callout--error" role="alert" x-init="$nextTick(() => $el.scrollIntoView({ block: 'nearest' }))">
                <p><strong>Not sent.</strong> {{ $result['message'] }}</p>
                <p class="mail-muted">Nothing left the Hub, and your draft is safe. Fix the problem above and send again.</p>
                <button type="button" class="mail-btn mail-btn--secondary" wire:click="dismissResult">Keep editing</button>
            </div>
        @elseif ($status === 'deferred')
            <div class="mail-callout mail-callout--warn" role="alert" x-init="$nextTick(() => $el.scrollIntoView({ block: 'nearest' }))">
                <p><strong>Not sent yet.</strong> {{ $result['message'] }}</p>
                <button type="button" class="mail-btn mail-btn--secondary" wire:click="dismissResult">Keep editing</button>
            </div>
        @elseif ($status === 'unconfirmed')
            <div class="mail-callout mail-callout--warn" role="alert" x-init="$nextTick(() => $el.scrollIntoView({ block: 'nearest' }))">
                <p><strong>Not confirmed.</strong> {{ $result['message'] }}</p>
                <p class="mail-muted">This draft is locked so it cannot go out twice.</p>
                <div class="mail-actions">
                    @if ($result['thread'])
                        <button type="button" class="mail-btn mail-btn--primary" wire:click="finish(true)">Check Sent</button>
                    @endif
                    <button type="button" class="mail-btn mail-btn--secondary" wire:click="finish(false)">Close</button>
                </div>
            </div>
        @endif

        <fieldset class="mail-compose__fields" @if ($locked) disabled @endif>
            <legend class="mail-sr">Message details</legend>

            <x-mail.recipients field="to" label="To" :draft="$draftId" />
            <div class="mail-compose__reveal">
                <button type="button" class="mail-link" x-show="!showCc" x-on:click="showCc = true">Add Cc</button>
                <button type="button" class="mail-link" x-show="!showBcc" x-on:click="showBcc = true">Add Bcc</button>
            </div>
            <div x-show="showCc" x-cloak><x-mail.recipients field="cc" label="Cc" :draft="$draftId" /></div>
            <div x-show="showBcc" x-cloak>
                <x-mail.recipients field="bcc" label="Bcc" :draft="$draftId" />
                <p class="mail-muted mail-hint">Bcc recipients are hidden from everyone else. Only you can see them afterwards.</p>
            </div>

            <p class="mail-count" :class="{ 'is-over': recipientCount > limit }" aria-live="polite">
                <span x-text="recipientCount"></span> of {{ $recipientLimit }} recipients
            </p>
            <div aria-live="assertive">
                <div class="mail-callout mail-callout--warn" x-show="recipientCount > limit" x-cloak>
                    <p>
                        The Email Center sends to at most {{ $recipientLimit }} {{ $recipientLimit === 1 ? 'person' : 'people' }} at a time. This message goes to
                        <strong x-text="recipientCount"></strong>.
                        For a message to a larger group, use
                        @if ($departmentUpdatesUrl)
                            <a class="mail-link" href="{{ $departmentUpdatesUrl }}">Department Updates</a>.
                        @else
                            Department Updates.
                        @endif
                    </p>
                </div>
            </div>

            <div x-show="recipientCount >= {{ \App\Livewire\Mail\MessageComposer::LARGE_SEND }}" x-cloak class="mail-field">
                <label class="mail-check">
                    <input type="checkbox" wire:model="reviewedRecipients">
                    <span>I have reviewed every recipient and want to send to this group.</span>
                </label>
            </div>

            <div class="mail-field">
                <label for="mail-subject-{{ $draftId }}" class="mail-label">Subject</label>
                <input id="mail-subject-{{ $draftId }}" type="text" class="mail-input" wire:model="subject" x-on:input="touch($event)" maxlength="998" autocomplete="off">
            </div>

            <div class="mail-field mail-compose__editor" x-ref="editor">
                {{ $this->form }}
            </div>

            <section class="mail-attach" aria-labelledby="mail-attach-title-{{ $draftId }}">
                <h3 class="mail-attach__title" id="mail-attach-title-{{ $draftId }}">Attachments</h3>
                <p class="mail-muted mail-hint">
                    Up to {{ $maxFiles }} files, {{ Words::fileSize($maxBytes) }} in total. PDF, Word, Excel, CSV, plain text, PNG, JPEG or GIF. Files stay private to the Hub.
                </p>
                @if ($attachments->isNotEmpty())
                    <ul class="mail-attachments" aria-label="Files attached to this message">
                        @foreach ($attachments as $file)
                            <li wire:key="mail-att-{{ $file->id }}" class="mail-attachment">
                                <x-filament::icon icon="heroicon-m-paper-clip" class="mail-icon" />
                                <span class="mail-attachment__name">{{ $file->filename }}</span>
                                <span class="mail-muted">{{ Words::fileSize((int) $file->size) }}</span>
                                <button type="button" class="mail-btn mail-btn--ghost" wire:click="removeAttachment({{ $file->id }})" aria-label="Remove {{ $file->filename }}">Remove</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if (count($forwardable) > 0)
                    <p class="mail-label">Files on the original message</p>
                    <ul class="mail-attachments">
                        @foreach ($forwardable as $original)
                            <li wire:key="mail-fwd-{{ $original['index'] }}" class="mail-attachment">
                                <x-filament::icon icon="heroicon-m-paper-clip" class="mail-icon" />
                                <span class="mail-attachment__name">{{ $original['filename'] }}</span>
                                <span class="mail-muted">{{ Words::fileSize($original['size']) }}</span>
                                @if ($original['available'])
                                    <button type="button" class="mail-btn mail-btn--secondary" wire:click="addForwarded({{ $original['index'] }})" aria-label="Include {{ $original['filename'] }}">Include</button>
                                @else
                                    <span class="mail-muted">no longer available</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                <label class="mail-btn mail-btn--secondary mail-file">
                    <x-filament::icon icon="heroicon-m-paper-clip" class="mail-icon" />
                    Attach files
                    <input type="file" multiple wire:model="uploads" accept="{{ $mime }}" class="mail-file__input">
                </label>
                <span class="mail-muted" wire:loading wire:target="uploads" role="status">Uploading…</span>
                @if ($attachError)
                    <p class="mail-callout mail-callout--error" role="alert">{{ $attachError }}</p>
                @endif
                @error('uploads.*') <p class="mail-callout mail-callout--error" role="alert">{{ $message }}</p> @enderror
            </section>
        </fieldset>

        @if (! $locked)
            <footer class="mail-compose__footer">
                <button
                    type="button"
                    class="mail-btn mail-btn--primary mail-send"
                    wire:click="send"
                    wire:loading.attr="disabled"
                    wire:target="send"
                    x-bind:disabled="recipientCount > limit || (recipientCount >= {{ \App\Livewire\Mail\MessageComposer::LARGE_SEND }} && !$wire.reviewedRecipients)"
                    data-mail-send
                >
                    <span wire:loading.remove wire:target="send">Send</span>
                    <span wire:loading wire:target="send">Sending…</span>
                </button>
                <button type="button" class="mail-btn mail-btn--ghost mail-btn--danger-text" wire:click="discard" wire:confirm="Discard this draft? The text and attachments will be deleted." data-mail-discard>Discard draft</button>
            </footer>
        @endif
    @endif
</section>
