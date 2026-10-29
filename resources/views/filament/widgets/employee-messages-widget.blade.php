<x-filament-widgets::widget>
@if (! $visible)
    <div hidden></div>
@else
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
        @endphp

        <div class="emsg-panel" wire:poll.30s.visible="poll" aria-labelledby="emsg-widget-title">
            <header class="emsg-panel__header">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 id="emsg-widget-title" class="emsg-panel__title">Employee Messages</h2>
                    @if ($unread > 0)
                        <span class="emsg-unread-dot">{{ $unread }} unread</span>
                    @endif
                </div>
                <a class="emsg-link-button" href="{{ \App\Filament\Admin\Pages\EmployeeMessages::getUrl() }}">View all messages</a>
            </header>

            @if ($threads->isEmpty())
                <div class="emsg-empty">
                    <x-filament::icon icon="heroicon-o-chat-bubble-bottom-center-text" class="emsg-empty__icon" />
                    <p class="emsg-subtle mt-2">No employee messages yet. Messages members send from the Employee Portal appear here.</p>
                </div>
            @else
                <ul
                    class="emsg-list"
                    aria-label="Latest employee conversations"
                    x-data
                    x-on:click.capture="if ($event.target.closest('[data-emsg-row]')) window.mbfdEmployeeMessagesConfirmLeave($event)"
                >
                    @foreach ($threads as $row)
                        @php
                            $isOpen = $expandedId === $row->id;
                            $rowUnread = $row->isUnreadForLogistics();
                            $rowAwaiting = $row->last_message_side === \App\Models\EmployeeMessaging\EmployeeLogisticsMessage::SIDE_MEMBER;
                            $rowDetails = $details($row);
                        @endphp
                        <li class="emsg-list__item" wire:key="emsg-widget-row-{{ $row->id }}">
                            <button
                                type="button"
                                data-emsg-row
                                wire:click="toggleThread({{ $row->id }})"
                                @class(['emsg-row', 'emsg-row--unread' => $rowUnread])
                                aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                                aria-controls="emsg-widget-conversation-{{ $row->id }}"
                                aria-current="{{ $isOpen ? 'true' : 'false' }}"
                            >
                                <span class="emsg-avatar" aria-hidden="true">{{ $initials($row->member_name) }}</span>
                                <span class="emsg-row__main">
                                    <span class="emsg-row__title">{{ $row->member_name }}</span>
                                    @if ($rowDetails !== '')
                                        <span class="emsg-row__sub">{{ $rowDetails }}</span>
                                    @endif
                                    @if (filled($row->last_message_preview))
                                        <span class="emsg-row__preview">{{ $row->last_message_preview }}</span>
                                    @endif
                                    @if ($rowAwaiting)
                                        <span class="emsg-tag mt-1">Awaiting reply</span>
                                    @endif
                                </span>
                                <span class="emsg-row__aside">
                                    @if ($row->last_message_at)
                                        <time datetime="{{ $row->last_message_at->copy()->timezone($zone)->toIso8601String() }}" title="{{ $row->last_message_at->copy()->timezone($zone)->format('l, F j, Y g:i A') }}">{{ $when($row->last_message_at) }}</time>
                                    @endif
                                    @if ($rowUnread)
                                        <span class="emsg-unread-dot">Unread</span>
                                    @endif
                                </span>
                            </button>

                            @if ($isOpen)
                                <div id="emsg-widget-conversation-{{ $row->id }}" class="border-t border-gray-100">
                                    <div
                                        class="emsg-thread-scroll"
                                        wire:key="emsg-widget-scroll-{{ $row->id }}"
                                        role="region"
                                        tabindex="0"
                                        aria-label="Conversation with {{ $row->member_name }}"
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
                                            :label="'Reply to '.$row->member_name"
                                            submit-label="Send reply"
                                            hint="Text only. The member sees this reply in My Messages."
                                            placeholder="Write your reply"
                                            :id-prefix="'emsg-widget-reply-'.$row->id"
                                            :thread-id="$row->id"
                                            wire:key="emsg-widget-composer-{{ $row->id }}"
                                        />
                                    @else
                                        <div class="emsg-composer">
                                            <p class="emsg-subtle">You can read employee messages but not reply.</p>
                                        </div>
                                    @endif

                                    <div class="emsg-more">
                                        <a class="emsg-link-button" href="{{ \App\Filament\Admin\Pages\EmployeeMessages::getUrl(['thread' => $row->id]) }}">Open in Employee Messages</a>
                                    </div>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
@endif
</x-filament-widgets::widget>
