@php
    /** @var array<string, mixed> $detail member-safe projection (SubmissionConversationProjection::memberView) */
    $when = fn ($at): string => $at ? \Carbon\CarbonImmutable::parse($at)->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A') : '';
@endphp
<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee" data-submission-conversation="{{ $detail['type'] }}:{{ $detail['id'] }}">
    <div class="hub-insp hub-insp-stack">
        <section class="hub-insp-card" aria-labelledby="msc-title">
            <div class="hub-insp-head">
                <div class="hub-insp-head__main">
                    <h2 class="hub-insp-title" id="msc-title">{{ $detail['title'] }}</h2>
                    <p class="hub-insp-sub">{{ $detail['reference'] }}</p>
                </div>
                <span class="hub-insp-badge hub-insp-badge--lg hub-insp-badge--{{ $detail['is_open'] ? 'warning' : 'neutral' }}">{{ $detail['status'] }}</span>
            </div>
            @if(filled($detail['description']))
                <p class="hub-insp-item__sub">What you sent</p>
                <p class="hub-insp-item__text">{{ $detail['description'] }}</p>
            @endif
        </section>

        @if(count($detail['updates']) > 0)
            <section class="hub-insp-card" aria-labelledby="msc-updates">
                <h3 class="hub-insp-card__title" id="msc-updates">Updates</h3>
                <ol class="hub-insp-timeline">
                    @foreach($detail['updates'] as $update)
                        <li class="hub-insp-timeline__item">
                            <p class="hub-insp-item__sub">{{ $when($update['at']) }} · {{ $update['from'] }}</p>
                            <p class="hub-insp-item__text">{{ $update['text'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        <section id="conversation" class="hub-insp-card hub-insp-anchor" aria-labelledby="msc-thread">
            <h3 class="hub-insp-card__title" id="msc-thread">Conversation</h3>
            @if(count($detail['messages']) === 0)
                <p class="hub-insp-note">No messages yet. Ask a question or add something here and the staff handling it will see it.</p>
            @else
                <ol class="hub-insp-timeline">
                    @foreach($detail['messages'] as $message)
                        <li class="hub-insp-timeline__item" data-message-side="{{ $message['from_staff'] ? 'staff' : 'member' }}">
                            <p class="hub-insp-item__sub">{{ $when($message['at']) }} · {{ $message['author'] }}</p>
                            <p class="hub-insp-item__text">{{ $message['body'] }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
            @if($detail['can_reply'])
                <form class="hub-insp-reply" wire:submit="sendReply">
                    <label for="msc-reply">Message the staff handling this</label>
                    <textarea id="msc-reply" wire:model="reply" rows="3" maxlength="{{ \App\Services\MemberReplies\SubmissionConversationService::MAX_BODY }}"
                        @error('reply') aria-invalid="true" aria-describedby="msc-reply-error" @enderror></textarea>
                    @error('reply')
                        <p id="msc-reply-error" role="alert">{{ $message }}</p>
                    @enderror
                    <button type="submit" wire:loading.attr="disabled" wire:target="sendReply">Send reply</button>
                </form>
            @else
                <p class="hub-insp-note">This record was archived, so it is closed to new messages.</p>
            @endif
        </section>
    </div>
</x-filament-panels::page>
