{{--
    Member-visible conversation. $messages are already filtered to member-visible rows by
    MemberInspectionConversation; internal notes never reach this view.
    Expects: $messages, $canPost, $heading, $emptyText, $fieldId, $model, $action, $label.
--}}
<div class="hub-insp-conversation">
    @if(count($messages) > 0)
        <ul class="hub-insp-thread" aria-label="{{ $heading }}">
            @foreach($messages as $message)
                <li class="hub-insp-message {{ $message['own'] ? 'hub-insp-message--own' : '' }}">
                    <header>
                        <strong>{{ $message['author'] }}</strong>
                        <time datetime="{{ $message['at']->toIso8601String() }}">{{ $message['at']->format('M j, Y g:i A') }}</time>
                    </header>
                    <p>{{ $message['body'] }}</p>
                </li>
            @endforeach
        </ul>
    @else
        <p class="hub-insp-note">{{ $emptyText }}</p>
    @endif

    @if($canPost)
        <form class="hub-insp-reply" wire:submit="{{ $action }}">
            <label for="{{ $fieldId }}">{{ $label }}</label>
            <textarea id="{{ $fieldId }}" wire:model="{{ $model }}" rows="3" maxlength="4000"
                @error($model) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror></textarea>
            @error($model)
                <p id="{{ $fieldId }}-error" role="alert">{{ $message }}</p>
            @enderror
            <button type="submit" wire:loading.attr="disabled" wire:target="{{ $action }}">Send reply</button>
        </form>
    @endif
</div>
