{{-- The part of the page that resources/js/daily-roster.js replaces when the roster version changes. --}}
<div data-roster-body data-version="{{ $roster['version'] }}" data-etag="{{ $roster['etag'] }}" data-state="{{ $roster['state'] }}" data-snapshot="{{ $roster['snapshotId'] }}" data-live="{{ $roster['isLive'] ? '1' : '0' }}">
    <header class="hub-page-header roster-header">
        <div class="roster-header__text">
            <p class="hub-page-header__eyebrow"><time datetime="{{ $roster['displayedDate'] }}">{{ $roster['displayedDateLabel'] }}</time></p>
            <h1 class="hub-h1">Daily Roster</h1>
            @if($roster['facts'] !== null)
                <p class="roster-header__division">{{ implode(' · ', $roster['facts']['division']) }}</p>
                <dl class="roster-facts">
                    <div><dt>Duty window</dt><dd>{{ $roster['facts']['window'] }}</dd></div>
                    <div><dt>Report generated</dt><dd data-roster-fact="generated">{{ $roster['facts']['generated'] }}</dd></div>
                    <div><dt>Last updated</dt><dd data-roster-fact="updated">{{ $roster['facts']['updated'] }}</dd></div>
                    <div data-roster-fact-row="changed"@if($roster['facts']['changedHidden']) hidden @endif><dt>Roster last changed</dt><dd data-roster-fact="changed">{{ $roster['facts']['changed'] }}</dd></div>
                </dl>
            @endif
        </div>
        <div class="roster-header__actions">
            <span class="hub-tag roster-freshness {{ $roster['tag']['class'] }}" data-roster-freshness>{{ $roster['tag']['label'] }}</span>
            <button type="button" id="roster-refresh" class="hub-btn hub-btn--secondary" data-roster-refresh>
                <x-heroicon-o-arrow-path class="hub-btn__icon" aria-hidden="true" />Refresh
            </button>
            @unless($roster['isEmpty'])
                <a class="hub-btn hub-btn--secondary" href="{{ route('daily-roster.pdf', array_filter(['date' => $roster['requestedDate']])) }}" data-roster-export>
                    <x-heroicon-o-arrow-down-tray class="hub-btn__icon" aria-hidden="true" />Export PDF
                </a>
            @endunless
            <p class="roster-connection" role="status" data-roster-connection hidden></p>
        </div>
    </header>

    @if($roster['notice'] !== null)
        <p class="hub-callout hub-callout--warning roster-notice" data-roster-notice>{{ $roster['notice'] }}</p>
    @endif

    @if($roster['mode'] === 'dated')
        <p class="hub-callout roster-notice" data-roster-dated>
            You are viewing the roster for {{ $roster['displayedDateLabel'] }}.
            <a class="hub-link" href="{{ route('daily-roster.show') }}">Go to the current roster</a>
        </p>
    @endif

    @if($roster['isEmpty'])
        <section class="hub-panel hub-empty" data-roster-empty>
            <x-heroicon-o-calendar-days class="hub-empty__icon" aria-hidden="true" />
            <h2 class="hub-empty__title">No roster has been published yet</h2>
            <p class="hub-empty__body">The roster appears here on its own after TeleStaff sends it.</p>
            <a class="hub-btn hub-btn--secondary roster-empty__action" href="{{ url('/') }}">Hub Home</a>
        </section>
    @else
        @include('daily-roster._summary', ['summary' => $roster['summary']])

        @if($roster['previousDate'] !== null || $roster['nextDate'] !== null)
            <nav class="roster-dates" aria-label="Roster dates">
                @if($roster['previousDate'] !== null)
                    <a class="hub-btn hub-btn--tertiary" rel="prev" href="{{ $roster['previousDate']['href'] }}"><x-heroicon-o-chevron-left class="hub-btn__icon" aria-hidden="true" />Previous: {{ $roster['previousDate']['label'] }}</a>
                @endif
                @if($roster['nextDate'] !== null)
                    <a class="hub-btn hub-btn--tertiary roster-dates__next" rel="next" href="{{ $roster['nextDate']['href'] }}">Next: {{ $roster['nextDate']['label'] }}<x-heroicon-o-chevron-right class="hub-btn__icon" aria-hidden="true" /></a>
                @endif
            </nav>
        @endif

        <details class="hub-panel roster-legend" data-roster-legend>
            <summary class="roster-legend__summary">
                <span>Status legend</span>
                <x-heroicon-o-chevron-down class="roster-chevron" aria-hidden="true" />
            </summary>
            @include('daily-roster._legend_items', ['legend' => $roster['legend']])
        </details>

        <form class="hub-panel roster-toolbar" role="search" aria-label="Search the roster" data-roster-filter>
            <div class="roster-field roster-field--search">
                <label for="roster-search">Search</label>
                <input id="roster-search" type="search" placeholder="Name, ID or unit" autocomplete="off" enterkeyhint="search" data-roster-search>
            </div>
            <div class="roster-field">
                <label for="roster-status">Status</label>
                <select id="roster-status" data-roster-status>
                    <option value="">All statuses</option>
                    @foreach($roster['filters'] as $filter)
                        <option value="{{ $filter }}">{{ $filter }}</option>
                    @endforeach
                </select>
            </div>
            <div class="roster-toolbar__actions">
                <button type="button" class="hub-btn hub-btn--tertiary" data-roster-clear hidden>Clear</button>
                <button type="button" class="hub-btn hub-btn--tertiary" data-roster-expand>Expand all</button>
                <button type="button" class="hub-btn hub-btn--tertiary" data-roster-collapse>Collapse all</button>
            </div>
            <p class="roster-toolbar__count" role="status" data-roster-count>{{ $roster['summary']['entries'] }} {{ $roster['summary']['entries'] === 1 ? 'entry' : 'entries' }} in {{ $roster['summary']['sections'] }} {{ $roster['summary']['sections'] === 1 ? 'section' : 'sections' }}</p>
        </form>

        <div class="hub-panel hub-empty" data-roster-no-match hidden>
            <p class="hub-empty__title">No entries match</p>
            <p class="hub-empty__body">Try a different name, ID or unit, or clear the search.</p>
        </div>

        <div class="roster-sections">
            @foreach($roster['sections'] as $section)
                @include('daily-roster._section', ['section' => $section])
            @endforeach
        </div>
    @endif
</div>
