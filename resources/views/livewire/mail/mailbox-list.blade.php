@php
    use App\Support\Mail\MailPresentation as Words;

    $pageUrl = static fn (array $query): string => \App\Filament\Admin\Pages\EmailCenter::getUrl($query);
@endphp
{{-- One root element. The poll refreshes this component only; the composer is a sibling and is never touched. --}}
<div class="mail-list" wire:poll.60s.visible="$refresh" data-mail-list>
    <div wire:loading.delay.short wire:target="loadMore,refreshList,$refresh" class="mail-busybar" role="status">Updating…</div>

    @if ($error)
        <div class="mail-state" role="alert">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mail-state__icon" />
            <p class="mail-state__title">This list could not be loaded</p>
            <p class="mail-muted">Your mail is safe. Check your connection and try again.</p>
            <button type="button" class="mail-btn mail-btn--secondary" wire:click="$refresh">Try again</button>
        </div>
    @elseif ($draftsMode)
        @if (count($drafts) === 0)
            <div class="mail-state">
                <x-filament::icon icon="heroicon-o-pencil-square" class="mail-state__icon" />
                <p class="mail-state__title">No drafts</p>
                <p class="mail-muted">Messages you start are saved here automatically until you send or discard them.</p>
            </div>
        @else
            <ul class="mail-rows" aria-label="Drafts">
                @foreach ($drafts as $draft)
                    @php
                        $recipients = collect((array) $draft->to)->filter()->implode(', ');
                        $locked = $draft->state === 'unconfirmed';
                    @endphp
                    <li wire:key="mail-draft-{{ $draft->id }}">
                        <a
                            href="{{ $pageUrl(['folder' => 'drafts', 'compose' => $draft->mode, 'draft' => $draft->id, 'thread' => $draft->thread_id]) }}"
                            wire:click.prevent="openDraft({{ $draft->id }})"
                            class="mail-row"
                            data-mail-row
                            data-mail-draft="{{ $draft->id }}"
                        >
                            <span class="mail-row__dot" aria-hidden="true"></span>
                            <span class="mail-row__main">
                                <span class="mail-row__top">
                                    <span class="mail-row__from">{{ $recipients !== '' ? 'To: '.$recipients : 'No recipient yet' }}</span>
                                    <time class="mail-row__time" datetime="{{ Words::isoTime($draft->updated_at) }}">{{ Words::listTime($draft->updated_at) }}</time>
                                </span>
                                <span class="mail-row__subject">{{ filled($draft->subject) ? $draft->subject : '(no subject)' }}</span>
                                <span class="mail-row__meta">
                                    <span class="mail-tag mail-tag--draft">{{ $locked ? 'Draft: delivery not confirmed' : 'Draft' }}</span>
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    @elseif (count($rows) === 0)
        <div class="mail-state">
            <x-filament::icon icon="heroicon-o-inbox" class="mail-state__icon" />
            @if ($filtered)
                <p class="mail-state__title">No conversations match</p>
                <p class="mail-muted">Try a different search, or clear the filters to see everything in {{ $folderLabel }}.</p>
                <button type="button" class="mail-btn mail-btn--secondary" wire:click="$parent.clearFilters()">Clear search and filters</button>
            @else
                <p class="mail-state__title">{{ $folderLabel }} is empty</p>
                <p class="mail-muted">
                    @switch($folder)
                        @case('inbox') New mail from outside the department arrives here. @break
                        @case('assigned') Conversations assigned to you appear here. @break
                        @case('starred') Star a conversation to find it here. @break
                        @case('sent') Messages sent from the Email Center appear here. @break
                        @case('automated') Roster imports and system notifications are kept here for reference. @break
                        @case('archive') Archived conversations are kept here. @break
                        @default Conversations moved to Trash wait here until they are restored. @break
                    @endswitch
                </p>
            @endif
        </div>
    @else
        <ul class="mail-rows" aria-label="{{ $folderLabel }} conversations">
            @foreach ($rows as $row)
                @php
                    $unreadRow = (bool) $row->getAttribute('is_unread');
                    $starred = (bool) $row->getAttribute('is_starred');
                    $people = collect((array) $row->participants)
                        ->filter(fn ($p) => is_array($p) && isset($p['address']) && ! in_array(strtolower((string) $p['address']), $own, true))
                        ->map(fn ($p) => Words::displayName((string) $p['address'], $p['name'] ?? null))
                        ->unique()->values();
                    $from = $people->isEmpty() ? 'Department mailbox' : $people->take(2)->implode(', ').($people->count() > 2 ? ' +'.($people->count() - 2) : '');
                    $selectedRow = $selected === (int) $row->id;
                @endphp
                <li wire:key="mail-thread-{{ $row->id }}">
                    <a
                        href="{{ $pageUrl(['folder' => $folder, 'thread' => $row->id]) }}"
                        wire:click.prevent="open({{ $row->id }})"
                        @class(['mail-row', 'mail-row--unread' => $unreadRow])
                        @if ($selectedRow) aria-current="true" @endif
                        data-mail-row
                        data-mail-thread="{{ $row->id }}"
                        data-unread="{{ $unreadRow ? '1' : '0' }}"
                    >
                        <span class="mail-row__dot" aria-hidden="true"></span>
                        <span class="mail-row__main">
                            <span class="mail-row__top">
                                <span class="mail-row__from">
                                    @if ($unreadRow)<span class="mail-sr">Unread. </span>@endif
                                    {{ $from }}@if ($row->message_count > 1)<span class="mail-row__count"> ({{ $row->message_count }})</span>@endif
                                </span>
                                <time class="mail-row__time" datetime="{{ Words::isoTime($row->last_message_at) }}">{{ Words::listTime($row->last_message_at) }}</time>
                            </span>
                            <span class="mail-row__subject">{{ filled($row->subject) ? $row->subject : '(no subject)' }}</span>
                            @if (filled($row->snippet))
                                <span class="mail-row__preview">{{ $row->snippet }}</span>
                            @endif
                            @php
                                $hasMeta = $row->has_attachments || $row->priority === 'high' || $starred || $row->assignee || $row->labels->isNotEmpty() || $row->kind === 'automated' || $row->snoozed_until || $row->trashed_at;
                            @endphp
                            @if ($hasMeta)
                                <span class="mail-row__meta">
                                    @if ($row->priority === 'high')<span class="mail-tag mail-tag--high">High priority</span>@endif
                                    @if ($row->has_attachments)
                                        <span class="mail-tag"><x-filament::icon icon="heroicon-m-paper-clip" class="mail-icon" />Attachment</span>
                                    @endif
                                    @if ($starred)
                                        <span class="mail-tag"><x-filament::icon icon="heroicon-m-star" class="mail-icon" />Starred</span>
                                    @endif
                                    @if ($row->assignee)
                                        <span class="mail-tag">Assigned: {{ $row->assignee->name }}</span>
                                    @endif
                                    @if ($row->snoozed_until && $row->snoozed_until->isFuture())
                                        <span class="mail-tag">Snoozed</span>
                                    @endif
                                    @foreach ($row->labels as $label)
                                        <span class="mail-tag">{{ $label->name }}</span>
                                    @endforeach
                                    @if ($row->kind === 'automated')<span class="mail-tag">Automated</span>@endif
                                </span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>

        @if ($hasMore)
            <div class="mail-more">
                <button type="button" class="mail-btn mail-btn--secondary" wire:click="loadMore" wire:loading.attr="disabled" wire:target="loadMore">
                    <span wire:loading.remove wire:target="loadMore">Show older conversations</span>
                    <span wire:loading wire:target="loadMore">Loading…</span>
                </button>
            </div>
        @elseif ($capped)
            <p class="mail-more mail-muted">Showing the {{ count($rows) }} newest. Search or filter to narrow the list.</p>
        @endif
    @endif
</div>
