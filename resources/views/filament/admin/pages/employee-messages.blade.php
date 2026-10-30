<x-filament-panels::page>
    {{-- Composer behavior must exist before any composer can appear through a Livewire update. --}}
    <x-employee-messages.assets />

    @php
        $zone = \App\Support\HubTimezone::NAME;
        $initials = static function (string $name): string {
            $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $first = mb_substr($parts[0] ?? '?', 0, 1);
            $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

            return mb_strtoupper($first.$last);
        };
        $details = static fn ($row): string => collect([
            filled($row->member_employee_number) ? 'Employee #'.$row->member_employee_number : null,
            $row->member_rank,
            $row->member_station,
        ])->filter(fn ($part) => filled($part))->implode(' · ');
        $when = static function ($at) use ($zone): string {
            if ($at === null) {
                return '';
            }
            $local = $at->copy()->timezone($zone);
            $today = now($zone);

            return match (true) {
                $local->isSameDay($today) => $local->format('g:i A'),
                $local->isSameDay($today->copy()->subDay()) => 'Yesterday',
                $local->year === $today->year => $local->format('M j'),
                default => $local->format('M j, Y'),
            };
        };
        $showConversation = $thread !== null || $threadMissing;
    @endphp

    <div wire:poll.30s.visible="poll">
        <div class="emsg-layout">
            {{-- ------------------------------------------------ conversation list --}}
            <section @class(['emsg-panel', 'hidden lg:block' => $showConversation]) aria-labelledby="emsg-admin-list-title">
                <header class="emsg-panel__header">
                    <h2 id="emsg-admin-list-title" class="emsg-panel__title">Conversations</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($awaitingTotal > 0)
                            <span class="emsg-status emsg-status--attention" data-emsg-awaiting>{{ $awaitingTotal }} awaiting reply</span>
                        @endif
                        @if ($unreadTotal > 0)
                            <span class="emsg-unread-dot">{{ $unreadTotal }} unread</span>
                        @endif
                    </div>
                </header>

                <div class="emsg-toolbar">
                    <div role="search" class="w-full">
                        <label for="emsg-admin-search" class="sr-only">Search Support Services messages</label>
                        <input
                            id="emsg-admin-search"
                            type="search"
                            class="emsg-search w-full"
                            wire:model.live.debounce.400ms="search"
                            placeholder="Search name, number, station, reason or message"
                            autocomplete="off"
                            maxlength="100"
                            enterkeyhint="search"
                        >
                    </div>
                    <div class="w-full">
                        <label for="emsg-admin-reason" class="sr-only">Filter by reason</label>
                        <select id="emsg-admin-reason" class="emsg-search emsg-select w-full" wire:model.live="reasonFilter" data-emsg-reason-filter>
                            <option value="">All reasons</option>
                            @foreach ($reasons as $key => $label)
                                <option value="{{ $key }}" @selected($reasonFilter === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="emsg-filters" role="group" aria-label="Show conversations">
                        @foreach ($filters as $value => $label)
                            <button
                                type="button"
                                class="emsg-filter"
                                wire:click="setFilter('{{ $value }}')"
                                aria-pressed="{{ $filter === $value ? 'true' : 'false' }}"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                @if ($threads->isEmpty())
                    <div class="emsg-empty">
                        <x-filament::icon icon="heroicon-o-chat-bubble-bottom-center-text" class="emsg-empty__icon" />
                        @if ($filtered)
                            <p class="emsg-empty__title">No conversations match</p>
                            <p class="emsg-subtle">Try a different search or show all conversations.</p>
                        @else
                            <p class="emsg-empty__title">No Support Services messages yet</p>
                            <p class="emsg-subtle">Messages members send from the Employee Portal appear here.</p>
                        @endif
                    </div>
                @else
                    <ul class="emsg-list" aria-label="Support Services conversations" x-data x-on:click.capture="window.mbfdEmployeeMessagesConfirmLeave($event)">
                        @foreach ($threads as $row)
                            @php
                                $rowUnread = $row->isUnreadForLogistics();
                                $rowAwaiting = $row->last_message_side === \App\Models\EmployeeMessaging\EmployeeLogisticsMessage::SIDE_MEMBER;
                                $rowDetails = $details($row);
                            @endphp
                            <li class="emsg-list__item" wire:key="emsg-admin-row-{{ $row->id }}">
                                <button
                                    type="button"
                                    wire:click="openThread({{ $row->id }})"
                                    @class(['emsg-row', 'emsg-row--unread' => $rowUnread])
                                    aria-current="{{ $selectedId === $row->id ? 'true' : 'false' }}"
                                >
                                    <x-employee-messages.admin-row :row="$row" :initials="$initials($row->member_name)" :details="$rowDetails" :when="$when($row->last_message_at)" />
                                </button>
                            </li>
                        @endforeach
                    </ul>

                    @if ($hasMore)
                        <div class="emsg-more">
                            <button type="button" class="emsg-link-button" wire:click="showMore" wire:loading.attr="disabled" wire:target="showMore">Show more</button>
                        </div>
                    @endif
                @endif
            </section>

            {{-- ------------------------------------------------ open conversation --}}
            <section @class(['emsg-panel', 'hidden lg:block' => ! $showConversation]) aria-label="Conversation">
                @if ($thread !== null)
                    @php $threadDetails = $details($thread); @endphp
                    <header class="emsg-panel__header">
                        <div class="min-w-0">
                            <h2 class="emsg-panel__title">{{ $thread->member_name }}</h2>
                            @if ($threadDetails !== '')
                                <p class="emsg-subtle">{{ $threadDetails }}</p>
                            @endif
                            @if (filled($thread->subject))
                                <p class="emsg-subtle">Subject: <strong class="emsg-strong">{{ $thread->subject }}</strong></p>
                            @endif
                            @if ($thread->reasonTag() !== null)
                                <p class="emsg-subtle">Reason: <strong class="emsg-strong">{{ $thread->reasonTag() }}</strong></p>
                            @endif
                        </div>
                        <div class="lg:hidden" x-data x-on:click.capture="window.mbfdEmployeeMessagesConfirmLeave($event)">
                            <button type="button" class="emsg-link-button" wire:click="closeThread">
                                <x-filament::icon icon="heroicon-m-arrow-left" class="h-4 w-4" />
                                Back to all messages
                            </button>
                        </div>
                    </header>

                    <div
                        class="emsg-thread-scroll"
                        wire:key="emsg-admin-scroll-{{ $thread->id }}"
                        role="region"
                        tabindex="0"
                        aria-label="Conversation with {{ $thread->member_name }}"
                        x-data="{
                            stick: true,
                            observer: null,
                            init() {
                                const el = this.$el
                                this.$nextTick(() => { el.scrollTop = el.scrollHeight })
                                this.observer = new MutationObserver(() => { if (this.stick) el.scrollTop = el.scrollHeight })
                                this.observer.observe(el, { childList: true, subtree: true })
                            },
                            destroy() { if (this.observer) this.observer.disconnect() },
                            onScroll() { const el = this.$el; this.stick = (el.scrollHeight - el.scrollTop - el.clientHeight) < 80 },
                        }"
                        x-on:scroll.passive="onScroll()"
                    >
                        <x-employee-messages.thread :messages="$messages" viewer-side="logistics" :has-earlier="$hasEarlier" />
                    </div>

                    @if ($canReply)
                        <x-employee-messages.composer
                            method="sendReply"
                            :label="'Reply to '.$thread->member_name"
                            submit-label="Send reply"
                            hint="Text only. The member sees this reply in My Messages."
                            placeholder="Write your reply"
                            :id-prefix="'emsg-admin-reply-'.$thread->id"
                            :thread-id="$thread->id"
                            wire:key="emsg-admin-composer-{{ $thread->id }}"
                        />
                    @else
                        <div class="emsg-composer">
                            <p class="emsg-subtle">You can read Support Services messages but not reply.</p>
                        </div>
                    @endif
                @elseif ($threadMissing)
                    <div class="emsg-empty">
                        <x-filament::icon icon="heroicon-o-chat-bubble-bottom-center-text" class="emsg-empty__icon" />
                        <p class="emsg-empty__title">That conversation could not be found</p>
                        <p class="emsg-subtle">It may have been opened from an old link. Choose a conversation from the list.</p>
                        <div class="mt-2 flex justify-center">
                            <button type="button" class="emsg-link-button" wire:click="closeThread">Back to all messages</button>
                        </div>
                    </div>
                @else
                    <div class="emsg-empty">
                        <x-filament::icon icon="heroicon-o-chat-bubble-bottom-center-text" class="emsg-empty__icon" />
                        <p class="emsg-empty__title">Select a conversation</p>
                        <p class="emsg-subtle">Choose a message from the list to read it and reply.</p>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
