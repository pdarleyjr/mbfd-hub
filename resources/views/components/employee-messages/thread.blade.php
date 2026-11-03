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
            <p class="emsg-bubble__body">{{ $message->body }}</p>
        </li>
    @empty
        <li class="emsg-thread__empty">{{ $emptyText }}</li>
    @endforelse
</ol>
