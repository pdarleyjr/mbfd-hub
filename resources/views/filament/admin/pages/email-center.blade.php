<x-filament-panels::page>
    <x-mail.assets />

    @php
        $view = $folder === 'health' ? 'reader' : ($composing ? 'compose' : ($threadId !== null ? 'reader' : 'list'));
        $listKey = 'list-'.md5(implode('|', [$folder, $q, $unread, $attachments, $label, $priority]));
        $showFilters = ! in_array($folder, ['drafts', 'health'], true);
    @endphp

    <div
        class="mail-shell"
        data-hub-ui="2"
        data-mail-shell
        data-view="{{ $view }}"
        data-folder="{{ $folder }}"
        x-data="Object.assign(mbfdMailShortcuts(), { online: navigator.onLine !== false })"
        x-on:online.window="online = true"
        x-on:offline.window="online = false"
    >
        <p class="mail-offline" x-show="!online" x-cloak role="status">
            Requires connection. Email can only be read and sent while you are online, and nothing is queued for later.
        </p>

        {{-- ------------------------------------------------------------------ folders --}}
        <aside class="mail-nav" aria-label="Mail folders">
            @if ($canSend)
                <button type="button" class="mail-btn mail-btn--primary mail-compose-btn" wire:click="startNewMessage" data-mail-action="compose" data-mail-nav>
                    <x-filament::icon icon="heroicon-m-pencil-square" class="mail-icon" />
                    Compose
                </button>
            @endif

            <div class="mail-nav__select">
                <label for="mail-folder-select" class="mail-sr">Folder</label>
                <select id="mail-folder-select" class="mail-input" wire:change="openFolder($event.target.value)" data-mail-nav>
                    @foreach ($folderItems as $item)
                        <option value="{{ $item['key'] }}" @selected($folder === $item['key'])>
                            {{ $item['label'] }}{{ $item['count'] && ($counts[$item['key']] ?? 0) > 0 ? ' ('.$counts[$item['key']].')' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <nav class="mail-nav__list" aria-label="Folders">
                <ul>
                    @foreach ($folderItems as $item)
                        @php $n = $item['count'] ? ($counts[$item['key']] ?? 0) : 0; @endphp
                        <li>
                            <a
                                href="{{ \App\Filament\Admin\Pages\EmailCenter::getUrl(['folder' => $item['key']]) }}"
                                wire:click.prevent="openFolder('{{ $item['key'] }}')"
                                class="mail-nav__item"
                                @if ($folder === $item['key']) aria-current="page" @endif
                                data-mail-nav
                                data-mail-folder="{{ $item['key'] }}"
                            >
                                <x-filament::icon :icon="$item['icon']" class="mail-icon" />
                                <span class="mail-nav__label">{{ $item['label'] }}</span>
                                @if ($n > 0)
                                    <span class="mail-count-pill" aria-label="{{ $n }} {{ $item['key'] === 'inbox' ? 'unread' : '' }}">{{ $n > 99 ? '99+' : $n }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <button type="button" class="mail-btn mail-btn--ghost mail-help-btn" x-on:click="openHelp($event)">
                <x-filament::icon icon="heroicon-m-question-mark-circle" class="mail-icon" />
                Keyboard shortcuts
            </button>
        </aside>

        {{-- ------------------------------------------------------------------ list --}}
        @if ($folder !== 'health')
            <section class="mail-listpane" aria-label="{{ ucfirst($folder) }} conversations">
                <div class="mail-listpane__tools">
                    @if ($folder !== 'drafts')
                        <div role="search" class="mail-search">
                            <label for="mail-search" class="mail-sr">Search mail</label>
                            <input
                                id="mail-search"
                                type="search"
                                class="mail-input"
                                wire:model.live.debounce.400ms="q"
                                placeholder="Search sender, subject or message"
                                autocomplete="off"
                                maxlength="100"
                                enterkeyhint="search"
                                data-mail-search
                            >
                        </div>
                    @endif
                    @if ($showFilters)
                        <div class="mail-filters" role="group" aria-label="Filter conversations">
                            <button type="button" class="mail-filter" wire:click="toggleFilter('unread')" aria-pressed="{{ $unread === '1' ? 'true' : 'false' }}">Unread</button>
                            <button type="button" class="mail-filter" wire:click="toggleFilter('attachments')" aria-pressed="{{ $attachments === '1' ? 'true' : 'false' }}">Has attachment</button>
                            <button type="button" class="mail-filter" wire:click="toggleFilter('priority')" aria-pressed="{{ $priority === 'high' ? 'true' : 'false' }}">High priority</button>
                            <div class="mail-filter-label">
                                <label for="mail-label-filter" class="mail-sr">Filter by label</label>
                                <select id="mail-label-filter" class="mail-input" wire:model.live="label">
                                    <option value="">All labels</option>
                                    @foreach ($labels as $name)
                                        <option value="{{ $name }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if ($filtersActive)
                                <button type="button" class="mail-btn mail-btn--ghost" wire:click="clearFilters">Clear</button>
                            @endif
                        </div>
                    @endif
                </div>

                <livewire:mail.mailbox-list
                    :folder="$folder"
                    :search="$q"
                    :unread="$unread"
                    :attachments="$attachments"
                    :label="$label"
                    :priority="$priority"
                    :selected="$threadId"
                    wire:key="{{ $listKey }}"
                />
            </section>
        @endif

        {{-- ------------------------------------------------------------------ reading pane --}}
        <section class="mail-readpane" aria-label="{{ $folder === 'health' ? 'Email health' : 'Message' }}">
            @if ($folder === 'health')
                @include('filament.admin.pages.email-center-health')
            @elseif ($composing)
                <livewire:mail.message-composer
                    :mode="$compose"
                    :thread-id="$threadId"
                    :source="$sourceKind !== null ? $sourceKind.':'.$sourceId : null"
                    :resume="$draftId"
                    wire:key="composer-{{ $composeRun }}-{{ $compose }}"
                />
            @elseif ($threadId !== null)
                <livewire:mail.message-reader :thread-id="$threadId" wire:key="reader-{{ $threadId }}" />
            @else
                <div class="mail-state mail-state--pane">
                    <x-filament::icon icon="heroicon-o-envelope-open" class="mail-state__icon" />
                    <p class="mail-state__title">Select a conversation</p>
                    <p class="mail-muted">Choose a message from the list to read it{{ $canSend ? ', or compose a new one' : '' }}.</p>
                    @if ($canSend)
                        <button type="button" class="mail-btn mail-btn--secondary" wire:click="startNewMessage">Compose a message</button>
                    @endif
                </div>
            @endif
        </section>

        {{-- ------------------------------------------------------------------ shortcut help --}}
        <div
            class="mail-help-overlay"
            x-show="helpOpen"
            x-cloak
            x-on:click.self="closeHelp()"
            x-on:keydown.escape.window="closeHelp()"
        >
            <div
                x-ref="help"
                class="mail-help"
                role="dialog"
                aria-modal="true"
                aria-labelledby="mail-help-title"
                tabindex="-1"
                x-on:keydown.tab="trap($event)"
            >
                <div class="mail-help__head">
                    <h2 id="mail-help-title">Email keyboard shortcuts</h2>
                    <button type="button" class="mail-btn mail-btn--ghost" x-ref="helpClose" x-on:click="closeHelp()" aria-label="Close keyboard shortcuts">Close</button>
                </div>
                <dl class="mail-help__list">
                    <div><dt><kbd>j</kbd> / <kbd>k</kbd></dt><dd>Open the next / previous conversation</dd></div>
                    <div><dt><kbd>c</kbd></dt><dd>Compose a new message</dd></div>
                    <div><dt><kbd>r</kbd></dt><dd>Reply</dd></div>
                    <div><dt><kbd>a</kbd></dt><dd>Reply all</dd></div>
                    <div><dt><kbd>f</kbd></dt><dd>Forward</dd></div>
                    <div><dt><kbd>e</kbd></dt><dd>Archive (or move back to the Inbox)</dd></div>
                    <div><dt><kbd>/</kbd></dt><dd>Search mail</dd></div>
                    <div><dt><kbd>?</kbd></dt><dd>Show this help</dd></div>
                </dl>
                <p class="mail-muted mail-help__foot">
                    Shortcuts never run while you are typing, and only on devices with a keyboard or mouse. Press Ctrl or Cmd with / to open the panel's shortcut settings, where single-key shortcuts can be turned off.
                </p>
            </div>
        </div>
    </div>
</x-filament-panels::page>
