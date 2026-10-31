<details class="hub-panel roster-section" open data-roster-section data-section-key="{{ $section['key'] }}" data-search="{{ $section['search'] }}">
    <summary class="roster-section__summary">
        @if($section['image']->kind === \App\Services\DailyRoster\RosterUnitImage::PICTOGRAM)
            <span class="roster-unit roster-unit--pictogram" data-unit-kind="pictogram"><x-heroicon-o-user-group aria-hidden="true" /></span>
        @else
            <span class="roster-unit roster-unit--{{ $section['image']->kind }}" data-unit-kind="{{ $section['image']->kind }}">
                <img src="{{ $section['image']->src }}" srcset="{{ $section['image']->srcset }}" alt="" width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" decoding="async" @unless($section['eager']) loading="lazy" @endunless>
            </span>
        @endif
        <span class="roster-section__text">
            <h2 class="roster-section__title">{{ $section['title'] }}</h2>
            <span class="roster-section__count" data-roster-section-count>{{ $section['entryCount'] }} {{ $section['entryCount'] === 1 ? 'entry' : 'entries' }}</span>
        </span>
        <x-heroicon-o-chevron-down class="roster-chevron" aria-hidden="true" />
    </summary>
    <div class="roster-section__body">
        <table class="roster-table" role="table" aria-label="{{ $section['title'] }}">
            <thead role="rowgroup">
                <tr role="row">
                    <th scope="col" role="columnheader" class="roster-col--rank">Rank</th>
                    <th scope="col" role="columnheader" class="roster-col--id">ID</th>
                    <th scope="col" role="columnheader">Member / Certifications</th>
                    <th scope="col" role="columnheader" class="roster-col--status">Status</th>
                    <th scope="col" role="columnheader" class="roster-col--time">From</th>
                    <th scope="col" role="columnheader" class="roster-col--time">Through</th>
                    <th scope="col" role="columnheader" class="roster-col--time">Hours</th>
                </tr>
            </thead>
            @foreach($section['seats'] as $seatIndex => $seat)
                @include('daily-roster._seat', ['seat' => $seat, 'seatIndex' => $seatIndex])
            @endforeach
        </table>
    </div>
</details>
