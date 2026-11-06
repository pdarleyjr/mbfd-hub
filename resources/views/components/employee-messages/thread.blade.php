@props([
    'messages',
    'viewerSide',
    'hasEarlier' => false,
    'emptyText' => 'No messages yet.',
])
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\EmployeeMessaging\EmployeeLogisticsMessage> $messages */
    $zone = \App\Support\HubTimezone::NAME;
@endphp
<ol {{ $attributes->class('emsg-thread') }} aria-label="Conversation">
    @if ($hasEarlier)
        <li class="emsg-thread__earlier">Showing the most recent {{ $messages->count() }} messages.</li>
    @endif
    @forelse ($messages as $message)
        @php
            $staffView = $viewerSide === \App\Models\EmployeeMessaging\EmployeeLogisticsMessage::SIDE_LOGISTICS;
            $fromAssistant = $message->isFromAssistant();
            // Members see the automated reply exactly like a Support Services reply (owner decision); staff see it marked.
            $fromLogistics = $message->isFromLogistics() || ($fromAssistant && ! $staffView);
            $markAssistant = $fromAssistant && $staffView;
            // The automated reply is never "mine" for staff: it is not something a team member wrote.
            $mine = ! $fromAssistant && $staffView === $message->isFromLogistics();
            $sideLabel = $markAssistant ? (string) config('ai_automation.staff_label', 'Support Services (automated)') : ($fromLogistics ? 'Support Services' : 'Member');
            $local = $message->created_at?->copy()->timezone($zone);
        @endphp
        <li wire:key="emsg-message-{{ $message->id }}" @class(['emsg-bubble', 'emsg-bubble--mine' => $mine, 'emsg-bubble--theirs' => ! $mine, 'emsg-bubble--logistics' => $fromLogistics, 'emsg-bubble--assistant' => $markAssistant])>
            <div class="emsg-bubble__meta">
                <span class="emsg-bubble__side">{{ $sideLabel }}</span>
                @unless ($fromAssistant)
                    <span class="emsg-bubble__author">{{ $message->author_name }}</span>
                @endunless
                @if ($local)
                    <time class="emsg-bubble__time" datetime="{{ $local->toIso8601String() }}" title="{{ $local->format('l, F j, Y g:i A') }}">{{ $local->isSameDay(now($zone)) ? $local->format('g:i A') : $local->format('M j, g:i A') }}</time>
                @endif
            </div>
            @php $long = mb_strlen((string) $message->body) > 600 || substr_count((string) $message->body, "\n") > 8; @endphp
            @if ($long)
                <div x-data="{ open: false }">
                    <div class="emsg-bubble__body" :class="open ? '' : 'emsg-bubble__body--clamped'" id="emsg-body-{{ $message->id }}">{{ $message->body }}</div>
                    <button type="button" class="emsg-showmore" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="emsg-body-{{ $message->id }}" data-emsg-showmore>
                        <span x-text="open ? 'Show less' : 'Show full message'">Show full message</span>
                    </button>
                </div>
            @else
                <div class="emsg-bubble__body">{{ $message->body }}</div>
            @endif
            @if ($message->personnel_document_id)
                {{-- A memorandum sent from the Hub: its PDF opens through the conversation's own access check. --}}
                <p class="emsg-bubble__attachment"><a href="{{ route('employee-messages.attachment', ['message' => $message->id]) }}" target="_blank" rel="noopener" data-hub-viewer="pdf" data-hub-viewer-title="Memorandum" data-hub-viewer-download="{{ route('employee-messages.attachment', ['message' => $message->id, 'download' => 1]) }}">Open memorandum (PDF)</a></p>
            @endif
        </li>
    @empty
        <li class="emsg-thread__empty">{{ $emptyText }}</li>
    @endforelse
</ol>
