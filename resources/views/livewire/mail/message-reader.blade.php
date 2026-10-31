@php
    use App\Support\Mail\MailPresentation as Words;
@endphp
<div class="mail-reader" data-mail-reader>
    @if ($state === 'missing')
        <div class="mail-state">
            <x-filament::icon icon="heroicon-o-question-mark-circle" class="mail-state__icon" />
            <p class="mail-state__title">That conversation could not be found</p>
            <p class="mail-muted">It may have been opened from an old link, or it was never indexed. Choose a conversation from the list.</p>
            <button type="button" class="mail-btn mail-btn--secondary" wire:click="$parent.closeThread()">Back to the list</button>
        </div>
    @elseif ($state === 'error' || $detail === null)
        <div class="mail-state" role="alert">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mail-state__icon" />
            <p class="mail-state__title">This conversation could not be loaded</p>
            <p class="mail-muted">Nothing was changed. Check your connection and try again.</p>
            <button type="button" class="mail-btn mail-btn--secondary" wire:click="$refresh">Try again</button>
        </div>
    @else
        @php
            $thread = $detail->thread;
            $trashed = $thread->isTrashed();
            $archived = $thread->archived_at !== null;
            $snoozed = $thread->snoozed_until !== null && $thread->snoozed_until->isFuture();
            $high = $thread->priority === 'high';
            $canAct = $detail->canAct;
            $canReply = $detail->canSend && ! $trashed;
        @endphp

        <div class="mail-reader__bar">
            <button type="button" class="mail-btn mail-btn--ghost mail-back" wire:click="$parent.closeThread()" data-mail-back>
                <x-filament::icon icon="heroicon-m-arrow-left" class="mail-icon" />
                Back to list
            </button>
        </div>

        <header class="mail-reader__header">
            <h2 class="mail-reader__subject" id="mail-reader-subject">{{ filled($thread->subject) ? $thread->subject : '(no subject)' }}</h2>
            <p class="mail-muted mail-reader__summary">
                {{ $thread->message_count }} {{ \Illuminate\Support\Str::plural('message', $thread->message_count) }}
                @if ($detail->isUnread) <span class="mail-tag mail-tag--unread">Unread</span> @endif
                @if ($high) <span class="mail-tag mail-tag--high">High priority</span> @endif
                @if ($archived && ! $trashed) <span class="mail-tag">Archived</span> @endif
                @if ($trashed) <span class="mail-tag mail-tag--bad">In Trash</span> @endif
                @if ($snoozed) <span class="mail-tag">Snoozed until {{ Words::fullTime($thread->snoozed_until) }}</span> @endif
                @if ($thread->assignee) <span class="mail-tag">Assigned to {{ $thread->assignee->name }}</span> @endif
                @foreach ($thread->labels as $tag)
                    <span class="mail-tag">{{ $tag->name }}</span>
                @endforeach
            </p>

            @if (! $canAct)
                <p class="mail-callout mail-callout--readonly" role="note">
                    <x-filament::icon icon="heroicon-o-lock-closed" class="mail-icon" />
                    <span>Automated message. It is kept for reference and is read-only: it cannot be replied to, archived or changed here.</span>
                </p>
            @endif

            @if ($error)
                <p class="mail-callout mail-callout--error" role="alert">{{ $error }}</p>
            @elseif ($notice)
                <p class="mail-callout mail-callout--ok" role="status">{{ $notice }}</p>
            @endif

            @if ($canAct)
                <div class="mail-actions" role="toolbar" aria-label="Conversation actions">
                    @if ($canReply)
                        <button type="button" class="mail-btn mail-btn--primary" wire:click="compose('reply')" data-mail-action="reply">
                            <x-filament::icon icon="heroicon-m-arrow-uturn-left" class="mail-icon" />Reply
                        </button>
                        <button type="button" class="mail-btn mail-btn--secondary" wire:click="compose('reply_all')" data-mail-action="reply_all">Reply all</button>
                        <button type="button" class="mail-btn mail-btn--secondary" wire:click="compose('forward')" data-mail-action="forward">
                            <x-filament::icon icon="heroicon-m-arrow-uturn-right" class="mail-icon" />Forward
                        </button>
                    @endif
                    <button type="button" class="mail-btn mail-btn--secondary" wire:click="toggleStar" aria-pressed="{{ $detail->isStarred ? 'true' : 'false' }}" data-mail-action="star">
                        <x-filament::icon :icon="$detail->isStarred ? 'heroicon-s-star' : 'heroicon-o-star'" class="mail-icon" />{{ $detail->isStarred ? 'Starred' : 'Star' }}
                    </button>
                    @if (! $detail->isUnread)
                        <button type="button" class="mail-btn mail-btn--secondary" wire:click="markUnread" data-mail-action="unread">Mark unread</button>
                    @endif
                    @if ($detail->canSend && ! $trashed)
                        @if ($archived)
                            <button type="button" class="mail-btn mail-btn--secondary" wire:key="mail-unarchive" wire:click="unarchive" data-mail-action="archive">Move to Inbox</button>
                        @else
                            <button type="button" class="mail-btn mail-btn--secondary" wire:key="mail-archive" wire:click="archive" data-mail-action="archive">
                                <x-filament::icon icon="heroicon-m-archive-box" class="mail-icon" />Archive
                            </button>
                        @endif
                    @endif
                    @if ($canManage)
                        @if ($trashed)
                            <button type="button" class="mail-btn mail-btn--secondary" wire:key="mail-restore" wire:click="restoreThread" data-mail-action="restore">Restore from Trash</button>
                        @else
                            <button type="button" class="mail-btn mail-btn--danger" wire:key="mail-trash" wire:click="trash" wire:confirm="Move this conversation to Trash? You can restore it later." data-mail-action="trash">
                                <x-filament::icon icon="heroicon-m-trash" class="mail-icon" />Trash
                            </button>
                        @endif
                    @endif
                </div>

                @if ($detail->canSend && ! $trashed)
                    <details class="mail-organize" wire:ignore.self>
                        <summary>Assign, priority, snooze and labels</summary>
                        <div class="mail-organize__grid">
                            <div class="mail-field">
                                <label for="mail-assign-{{ $thread->id }}" class="mail-label">Assigned to</label>
                                <select id="mail-assign-{{ $thread->id }}" class="mail-input" wire:change="assign($event.target.value)">
                                    <option value="">Nobody</option>
                                    @foreach ($assignable as $person)
                                        <option value="{{ $person['id'] }}" @selected((int) $thread->assigned_user_id === $person['id'])>{{ $person['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mail-field">
                                <span class="mail-label">Priority</span>
                                <button type="button" class="mail-btn mail-btn--secondary" wire:click="togglePriority" aria-pressed="{{ $high ? 'true' : 'false' }}">
                                    High priority
                                </button>
                            </div>
                            <div class="mail-field">
                                <label for="mail-snooze-{{ $thread->id }}" class="mail-label">Snooze</label>
                                <div class="mail-inline">
                                    <select id="mail-snooze-{{ $thread->id }}" class="mail-input" wire:model="snoozeChoice">
                                        @foreach ($snoozePresets as $value => $text)
                                            <option value="{{ $value }}">{{ $text }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="mail-btn mail-btn--secondary" wire:click="snooze">Snooze</button>
                                    @if ($snoozed)
                                        <button type="button" class="mail-btn mail-btn--ghost" wire:click="unsnooze">Wake now</button>
                                    @endif
                                </div>
                            </div>
                            <div class="mail-field">
                                <label for="mail-label-{{ $thread->id }}" class="mail-label">Labels</label>
                                <div class="mail-chips" aria-label="Labels on this conversation">
                                    @forelse ($thread->labels as $tag)
                                        <span class="mail-chip">{{ $tag->name }}
                                            <button type="button" class="mail-chip__x" wire:click="removeLabel({{ \Illuminate\Support\Js::from($tag->name) }})" aria-label="Remove label {{ $tag->name }}">&times;</button>
                                        </span>
                                    @empty
                                        <span class="mail-muted">No labels yet</span>
                                    @endforelse
                                </div>
                                <form class="mail-inline" wire:submit.prevent="addLabel">
                                    <input id="mail-label-{{ $thread->id }}" class="mail-input" type="text" wire:model="labelName" list="mail-labels-{{ $thread->id }}" maxlength="40" autocomplete="off" placeholder="Add a label">
                                    <datalist id="mail-labels-{{ $thread->id }}">
                                        @foreach ($knownLabels as $known)<option value="{{ $known }}"></option>@endforeach
                                    </datalist>
                                    <button type="submit" class="mail-btn mail-btn--secondary">Add label</button>
                                </form>
                            </div>
                        </div>
                    </details>
                @endif
            @endif
        </header>

        {{-- ------------------------------------------------------------- the conversation --}}
        <div class="mail-thread" role="region" aria-labelledby="mail-reader-subject">
            @foreach ($detail->messages as $m)
                @php
                    $who = Words::displayName($m->fromAddress, $m->fromName);
                    $bodyUrl = route('mail.messages.body', ['kind' => $m->kind, 'id' => $m->id]);
                    $recipients = collect($m->to)->map(fn ($p) => Words::displayName($p['address'], $p['name']))->take(3)->implode(', ');
                @endphp
                <article class="mail-message" wire:key="mail-msg-{{ $m->kind }}-{{ $m->id }}" aria-label="Message from {{ $who }}" @class(['mail-message--mine' => $m->kind === 'outbound'])>
                    <header class="mail-message__header">
                        <span class="mail-avatar" aria-hidden="true">{{ Words::initials($who) }}</span>
                        <div class="mail-message__who">
                            <p class="mail-message__from">{{ $who }}@if ($m->kind === 'outbound' && $m->isHuman) <span class="mail-tag">Sent from the Email Center</span>@endif</p>
                            <p class="mail-muted mail-message__to">To {{ $recipients !== '' ? $recipients : 'the department mailbox' }}@if (count($m->to) > 3), +{{ count($m->to) - 3 }} more @endif</p>
                        </div>
                        <time class="mail-muted mail-message__time" datetime="{{ Words::isoTime($m->at) }}">{{ Words::fullTime($m->at) }}</time>
                    </header>

                    <details class="mail-details" wire:ignore.self>
                        <summary>Sender and recipient details</summary>
                        <dl class="mail-details__list">
                            <div><dt>From</dt><dd>{{ Words::person($m->fromAddress, $m->fromName) }}</dd></div>
                            @if ($m->replyTo)<div><dt>Reply-To</dt><dd>{{ $m->replyTo }}</dd></div>@endif
                            <div><dt>To</dt><dd>{{ collect($m->to)->map(fn ($p) => Words::person($p['address'], $p['name']))->implode(', ') ?: 'The department mailbox' }}</dd></div>
                            @if (count($m->cc) > 0)<div><dt>Cc</dt><dd>{{ collect($m->cc)->map(fn ($p) => Words::person($p['address'], $p['name']))->implode(', ') }}</dd></div>@endif
                            @if (count($m->bcc) > 0)<div><dt>Bcc</dt><dd>{{ collect($m->bcc)->map(fn ($p) => Words::person($p['address'], $p['name']))->implode(', ') }} <span class="mail-muted">(only you can see this)</span></dd></div>@endif
                            <div><dt>Date</dt><dd>{{ Words::fullTime($m->at) }}</dd></div>
                            <div><dt>Subject</dt><dd>{{ $m->subject }}</dd></div>
                        </dl>
                    </details>

                    <div class="mail-message__body">
                        @if ($m->hasHtml)
                            {{-- Sanitized again by the route and shown only here: no scripts and not the Hub's origin. --}}
                            <div x-data="mbfdMailBody({ src: @js($bodyUrl) })" x-on:resize.window.debounce.300ms="measure()">
                                <iframe
                                    x-ref="frame"
                                    class="mail-frame"
                                    :style="'height:' + height + 'px'"
                                    src="{{ $bodyUrl }}"
                                    sandbox="allow-popups allow-popups-to-escape-sandbox"
                                    referrerpolicy="no-referrer"
                                    loading="lazy"
                                    title="Message body from {{ $who }}"
                                ></iframe>
                            </div>
                        @else
                            <div class="mail-text">{{ $m->text }}</div>
                        @endif
                    </div>

                    @if (count($m->skippedAttachments) > 0)
                        <p class="mail-callout mail-callout--note" role="note">
                            <x-filament::icon icon="heroicon-o-paper-clip" class="mail-icon" />
                            <span>
                                {{ count($m->skippedAttachments) }} {{ \Illuminate\Support\Str::plural('part', count($m->skippedAttachments)) }} of this message could not be kept
                                ({{ collect($m->skippedAttachments)->map(fn ($part) => ($part['filename'] ?? null) ?: ($part['mime_type'] ?? 'unnamed part'))->take(4)->implode(', ') }}).
                                The message itself arrived complete. Ask the sender to resend a file if you need it.
                            </span>
                        </p>
                    @endif

                    @if (count($m->attachments) > 0)
                        <ul class="mail-attachments" aria-label="Attachments">
                            @foreach ($m->attachments as $file)
                                <li>
                                    @if ($file['available'])
                                        <a class="mail-attachment" href="{{ route('mail.messages.attachment', ['kind' => $m->kind, 'id' => $m->id, 'index' => $file['index']]) }}" download>
                                            <x-filament::icon icon="heroicon-m-paper-clip" class="mail-icon" />
                                            <span class="mail-attachment__name">{{ $file['filename'] }}</span>
                                            <span class="mail-muted">{{ Words::fileSize((int) $file['size']) }}</span>
                                        </a>
                                    @else
                                        <span class="mail-attachment mail-attachment--gone">
                                            <x-filament::icon icon="heroicon-m-paper-clip" class="mail-icon" />
                                            <span class="mail-attachment__name">{{ $file['filename'] }}</span>
                                            <span class="mail-muted">no longer available</span>
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($m->kind === 'outbound' && $m->isHuman && $m->deliveryStatus !== null)
                        <section class="mail-delivery" aria-label="Delivery status">
                            <p class="mail-delivery__head">
                                <span class="mail-delivery__title">Delivery</span>
                                <span class="mail-badge mail-badge--{{ Words::tone($m->deliveryStatus) }}">{{ $m->deliveryStatus }}</span>
                            </p>
                            @if (count($delivery[$m->id] ?? []) > 0)
                                <ul class="mail-delivery__rows">
                                    @foreach ($delivery[$m->id] as $row)
                                        <li>
                                            <span class="mail-delivery__who">{{ $row['address'] }}@if ($row['class'] !== 'to') <span class="mail-muted">({{ strtoupper($row['class']) }})</span>@endif</span>
                                            <span class="mail-badge mail-badge--{{ $row['tone'] }}">{{ $row['label'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <p class="mail-muted mail-delivery__note">This shows what the sending service and receiving servers reported. It cannot show which mailbox folder a message reached.</p>
                        </section>
                    @endif

                    @if ($canReply && $m->canRespond && $canAct)
                        <div class="mail-message__actions">
                            <button type="button" class="mail-btn mail-btn--ghost" wire:click="compose('reply', '{{ $m->kind }}:{{ $m->id }}')">Reply to this</button>
                            <button type="button" class="mail-btn mail-btn--ghost" wire:click="compose('forward', '{{ $m->kind }}:{{ $m->id }}')">Forward this</button>
                        </div>
                    @endif
                </article>
            @endforeach

            @if ($detail->drafts->isNotEmpty() && $detail->canSend)
                <div class="mail-callout mail-callout--note" role="note">
                    <span>You have {{ $detail->drafts->count() }} unfinished {{ \Illuminate\Support\Str::plural('draft', $detail->drafts->count()) }} on this conversation.</span>
                    @foreach ($detail->drafts as $draft)
                        <button type="button" class="mail-btn mail-btn--secondary" wire:click="$dispatch('mail-open-draft', { id: {{ $draft->id }} })">Continue draft {{ $loop->iteration }}</button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ------------------------------------------------------------- internal notes --}}
        @if ($canAct)
            <section class="mail-notes" aria-labelledby="mail-notes-title-{{ $thread->id }}">
                <h3 class="mail-notes__title" id="mail-notes-title-{{ $thread->id }}">
                    <x-filament::icon icon="heroicon-o-lock-closed" class="mail-icon" />
                    Internal notes
                    <span class="mail-badge mail-badge--internal">Internal only. Never sent.</span>
                </h3>
                <p class="mail-notes__hint">Only colleagues with Email access can see these. They are not part of any message and are never included in a reply or forward.</p>
                @forelse ($detail->notes as $note)
                    <div class="mail-note" wire:key="mail-note-{{ $note->id }}">
                        <p class="mail-note__meta">{{ $note->author?->name ?? 'A colleague' }} <time datetime="{{ Words::isoTime($note->created_at) }}">{{ Words::fullTime($note->created_at) }}</time></p>
                        <p class="mail-note__body">{{ $note->body }}</p>
                    </div>
                @empty
                    <p class="mail-muted">No internal notes yet.</p>
                @endforelse
                @if ($detail->canSend && ! $trashed)
                    <form wire:submit.prevent="addNote" class="mail-notes__form">
                        <label for="mail-note-{{ $thread->id }}" class="mail-label">Add an internal note</label>
                        <textarea id="mail-note-{{ $thread->id }}" class="mail-input mail-textarea" rows="3" wire:model="noteBody" maxlength="10000" placeholder="Visible to colleagues only"></textarea>
                        <button type="submit" class="mail-btn mail-btn--secondary">Add internal note</button>
                    </form>
                @endif
            </section>
        @endif

        {{-- ------------------------------------------------------------- activity --}}
        @if ($detail->events->isNotEmpty())
            <details class="mail-activity" wire:ignore.self>
                <summary>Activity ({{ $detail->events->count() }})</summary>
                <ol class="mail-activity__list">
                    @foreach ($detail->events->reverse() as $event)
                        <li>
                            <span>{{ Words::eventSentence($event, $assigneeNames[$event->metadata['assignee_user_id'] ?? 0] ?? null) }}</span>
                            <time class="mail-muted" datetime="{{ Words::isoTime($event->created_at) }}">{{ Words::fullTime($event->created_at) }}</time>
                        </li>
                    @endforeach
                </ol>
            </details>
        @endif
    @endif
</div>
