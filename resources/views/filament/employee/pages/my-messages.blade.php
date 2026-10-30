<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <x-employee-messages.assets />
    @php
        $zone = \App\Support\HubTimezone::NAME;
        $today = now($zone);
        $showConversationOnMobile = $thread !== null || $composing || $missingThread;
    @endphp

    <div class="emsg-layout" wire:poll.30s.visible="poll" data-employee-messages="member">
        {{-- Conversation list --}}
        <section @class(['emsg-panel', 'max-lg:hidden' => $showConversationOnMobile]) aria-labelledby="emsg-member-list-heading">
            <div class="emsg-panel__header">
                <h2 id="emsg-member-list-heading" class="emsg-panel__title">Conversations with Support Services</h2>
                <button type="button" class="emsg-send" wire:click="startCompose" x-on:click.capture="window.mbfdEmployeeMessagesConfirmLeave($event)">
                    <x-heroicon-o-pencil-square class="emsg-send__icon" aria-hidden="true" />
                    New message
                </button>
            </div>

            @if ($threads->isEmpty())
                <div class="emsg-empty">
                    <x-heroicon-o-chat-bubble-left-ellipsis class="emsg-empty__icon" aria-hidden="true" />
                    <p class="emsg-empty__title">No messages yet</p>
                    <p class="emsg-subtle">Questions about equipment, uniforms, supplies or bunker gear cleaning? Send Support Services a message and their reply will appear here.</p>
                </div>
            @else
                <ul class="emsg-list" x-on:click.capture="window.mbfdEmployeeMessagesConfirmLeave($event)">
                    @foreach ($threads as $row)
                        @php
                            $isUnread = in_array($row->id, $unreadIds, true);
                            $at = $row->last_message_at?->copy()->timezone($zone);
                        @endphp
                        <li class="emsg-list__item" wire:key="emsg-member-row-{{ $row->id }}">
                            <button
                                type="button"
                                wire:click="openThread({{ $row->id }})"
                                @class(['emsg-row', 'emsg-row--unread' => $isUnread])
                                @if ($thread?->id === $row->id) aria-current="true" @endif
                            >
                                <span class="emsg-avatar" aria-hidden="true">
                                    <x-heroicon-o-truck class="h-5 w-5" />
                                </span>
                                <span class="emsg-row__main">
                                    <span class="emsg-row__title">{{ $row->title() }}</span>
                                    @if ($row->reasonTag() !== null && trim((string) $row->subject) !== '')
                                        <span class="emsg-row__sub">{{ $row->reasonTag() }}</span>
                                    @endif
                                    <span class="emsg-row__preview">
                                        @if ($row->last_message_side === 'logistics')<span class="font-semibold">Support Services:</span>@else<span class="font-semibold">You:</span>@endif
                                        {{ $row->last_message_preview }}
                                    </span>
                                </span>
                                <span class="emsg-row__aside">
                                    @if ($at)
                                        <time datetime="{{ $at->toIso8601String() }}">{{ $at->isSameDay($today) ? $at->format('g:i A') : $at->format('M j') }}</time>
                                    @endif
                                    @if (! $isUnread)
                                        <span @class(['emsg-status', 'emsg-status--answered' => $row->last_message_side === 'logistics']) data-emsg-status>{{ $row->memberStatus() }}</span>
                                    @endif
                                    @if ($isUnread)
                                        <span class="emsg-unread-dot">New<span class="sr-only"> reply from Support Services</span></span>
                                    @endif
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
                @if ($hasMore)
                    <div class="emsg-more">
                        <button type="button" class="emsg-link-button" wire:click="showMore">Show older conversations</button>
                    </div>
                @endif
            @endif
        </section>

        {{-- Conversation / composer --}}
        <section @class(['emsg-panel', 'max-lg:hidden' => ! $showConversationOnMobile]) aria-labelledby="emsg-member-detail-heading" aria-live="off">
            @if ($composing)
                <div class="emsg-panel__header">
                    <div>
                        <h2 id="emsg-member-detail-heading" class="emsg-panel__title">New message to Support Services</h2>
                        <p class="emsg-subtle">Only you and the Support Services team can see this conversation.</p>
                    </div>
                    <button type="button" class="emsg-link-button lg:hidden" wire:click="backToList" x-on:click.capture="window.mbfdEmployeeMessagesConfirmLeave($event)">
                        <x-heroicon-o-arrow-left class="h-4 w-4" aria-hidden="true" /> All messages
                    </button>
                </div>
                <x-employee-messages.composer
                    method="startThread"
                    :with-subject="true"
                    :with-reason="true"
                    :autofocus="true"
                    label="Message"
                    placeholder="What do you need from Support Services?"
                    submit-label="Send to Support Services"
                    id-prefix="emsg-member-new"
                    wire:key="emsg-member-new-composer"
                    class="emsg-composer--first"
                />
            @elseif ($missingThread)
                <div class="emsg-panel__header">
                    <h2 id="emsg-member-detail-heading" class="emsg-panel__title">Conversation not found</h2>
                    <button type="button" class="emsg-link-button lg:hidden" wire:click="backToList">
                        <x-heroicon-o-arrow-left class="h-4 w-4" aria-hidden="true" /> All messages
                    </button>
                </div>
                <div class="emsg-empty">
                    <p class="emsg-subtle">That conversation could not be found. It may be an old link. Your conversations are listed here, or you can start a new message.</p>
                    <button type="button" class="emsg-link-button" wire:click="startCompose">Start a new message</button>
                </div>
            @elseif ($thread !== null && $conversation !== null)
                <div class="emsg-panel__header">
                    <div class="min-w-0">
                        <h2 id="emsg-member-detail-heading" class="emsg-panel__title">{{ $thread->title() }}</h2>
                        <p class="emsg-subtle">Private conversation with Support Services · {{ $thread->message_count }} {{ \Illuminate\Support\Str::plural('message', $thread->message_count) }}</p>
                        @if ($thread->reasonTag() !== null)
                            <p class="emsg-subtle">Reason: {{ $thread->reasonTag() }} · <span data-emsg-status>{{ $thread->memberStatus() }}</span></p>
                        @else
                            <p class="emsg-subtle"><span data-emsg-status>{{ $thread->memberStatus() }}</span></p>
                        @endif
                    </div>
                    <button type="button" class="emsg-link-button lg:hidden" wire:click="backToList" x-on:click.capture="window.mbfdEmployeeMessagesConfirmLeave($event)">
                        <x-heroicon-o-arrow-left class="h-4 w-4" aria-hidden="true" /> All messages
                    </button>
                </div>
                <div class="emsg-thread-scroll" x-data="{ stick: true }" x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => { if (stick) $el.scrollTop = $el.scrollHeight; }).observe($el, { childList: true, subtree: true })" x-on:scroll.passive="stick = ($el.scrollHeight - $el.scrollTop - $el.clientHeight) < 80" wire:key="emsg-member-scroll-{{ $thread->id }}">
                    <x-employee-messages.thread
                        :messages="$conversation['messages']"
                        :has-earlier="$conversation['hasEarlier']"
                        viewer-side="member"
                    />
                </div>
                <x-employee-messages.composer
                    method="sendReply"
                    label="Reply to Support Services"
                    placeholder="Write a reply"
                    submit-label="Send reply"
                    id-prefix="emsg-member-reply-{{ $thread->id }}"
                    :thread-id="$thread->id"
                    wire:key="emsg-member-reply-composer-{{ $thread->id }}"
                />
            @else
                <div class="emsg-empty">
                    <x-heroicon-o-chat-bubble-bottom-center-text class="emsg-empty__icon" aria-hidden="true" />
                    <h2 id="emsg-member-detail-heading" class="emsg-empty__title">Message Support Services</h2>
                    <p class="emsg-subtle">Choose a conversation, or start a new message to the Support Services team.</p>
                    <button type="button" class="emsg-link-button" wire:click="startCompose">Start a new message</button>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
