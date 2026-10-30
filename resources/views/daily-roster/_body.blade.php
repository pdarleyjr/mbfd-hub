{{-- The part of the page that resources/js/daily-roster.js replaces when the roster version changes. --}}
<div data-roster-body data-version="{{ $roster['version'] }}">
    <header class="hub-page-header roster-header">
        <div class="roster-header__text">
            <p class="hub-page-header__eyebrow"><time datetime="{{ $roster['displayedDate'] }}">{{ $roster['displayedDateLabel'] }}</time></p>
            <h1 class="hub-h1">Daily Roster</h1>
            @if($roster['facts'] !== null)
                <p class="roster-header__division">{{ implode(' · ', $roster['facts']['division']) }}</p>
                <dl class="roster-facts">
                    <div><dt>Duty window</dt><dd>{{ $roster['facts']['window'] }}</dd></div>
                    <div><dt>Report generated</dt><dd>{{ $roster['facts']['generated'] }}</dd></div>
                    <div><dt>Last updated</dt><dd>{{ $roster['facts']['updated'] }}</dd></div>
                </dl>
            @endif
        </div>
        <div class="roster-header__actions">
            <span class="hub-tag roster-freshness {{ $roster['tag']['class'] }}" data-roster-freshness>{{ $roster['tag']['label'] }}</span>
            <button type="button" id="roster-refresh" class="hub-btn hub-btn--secondary" data-roster-refresh>
                <x-heroicon-o-arrow-path class="hub-btn__icon" aria-hidden="true" />Refresh
            </button>
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
        <ul class="roster-summary">
            <li class="hub-panel roster-summary__card">
                <span class="roster-summary__icon"><x-heroicon-o-calendar-days aria-hidden="true" /></span>
                <div><p class="roster-summary__label">Shift</p><p class="roster-summary__value">{{ $roster['summary']['shift'] }}</p><p class="roster-summary__detail">{{ $roster['summary']['date'] }}</p></div>
            </li>
            <li class="hub-panel roster-summary__card">
                <span class="roster-summary__icon"><x-heroicon-o-user-group aria-hidden="true" /></span>
                <div><p class="roster-summary__label">Sections</p><p class="roster-summary__value">{{ $roster['summary']['sections'] }}</p><p class="roster-summary__detail">Units and assignments</p></div>
            </li>
            <li class="hub-panel roster-summary__card">
                <span class="roster-summary__icon"><x-heroicon-o-document-text aria-hidden="true" /></span>
                <div><p class="roster-summary__label">Entries</p><p class="roster-summary__value">{{ $roster['summary']['entries'] }}</p><p class="roster-summary__detail">A member can appear twice</p></div>
            </li>
            <li class="hub-panel roster-summary__card">
                <span class="roster-summary__icon"><x-heroicon-o-circle-stack aria-hidden="true" /></span>
                <div><p class="roster-summary__label">Source</p><p class="roster-summary__value">TeleStaff</p><p class="roster-summary__detail">Roster report, version {{ $roster['summary']['version'] }}</p></div>
            </li>
        </ul>

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
            <ul class="roster-legend__list">
                @foreach($roster['legend'] as $pill)
                    <li>
                        <span class="hub-tag roster-pill roster-pill--{{ $pill->tone }}">{{ $pill->label }}</span>
                        <span class="roster-legend__meaning">{{ $pill->meaning }}</span>
                    </li>
                @endforeach
            </ul>
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
