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
            <span class="roster-section__count">{{ $section['entryCount'] }} {{ $section['entryCount'] === 1 ? 'entry' : 'entries' }}</span>
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
            @foreach($section['seats'] as $seat)
                <tbody role="rowgroup" class="roster-seat" data-roster-seat>
                    @foreach($seat as $row)
                        <tr role="row" class="roster-row @if($row['isVacant']) roster-row--vacant @endif" data-roster-row data-search="{{ $row['search'] }}" data-status="{{ $row['pill']->filterLabel }}">
                            <td role="cell" class="roster-cell roster-cell--rank">
                                @if($row['isFirstInSeat'])
                                    {{ $row['rank'] }}
                                @else
                                    <span class="roster-rank-continued">{{ $row['rank'] }}<span class="sr-only">, same seat</span></span>
                                @endif
                            </td>
                            <td role="cell" class="roster-cell roster-cell--id">{{ $row['employeeId'] }}</td>
                            <td role="cell" class="roster-cell roster-cell--member">
                                <span class="roster-member__name">{{ $row['name'] }}</span>
                                <span class="roster-member__meta" aria-hidden="true">
                                    <span class="@unless($row['isFirstInSeat']) roster-rank-continued @endunless">{{ $row['rank'] }}</span>
                                    @if($row['employeeId'] !== '')<span>ID {{ $row['employeeId'] }}</span>@endif
                                </span>
                                @if($row['sourceMarker'] !== '')
                                    <span class="roster-member__source">TeleStaff shows {{ $row['sourceMarker'] }}</span>
                                @endif
                                @if($row['certifications'] !== [])
                                    <span class="roster-member__certifications">@foreach($row['certifications'] as $certification){{ $loop->first ? '' : '/' }}<wbr>{{ $certification }}@endforeach</span>
                                @endif
                            </td>
                            <td role="cell" class="roster-cell roster-cell--status">
                                <span class="roster-cell__label" aria-hidden="true">Status</span>
                                <span class="hub-tag roster-pill roster-pill--{{ $row['pill']->tone }}" title="{{ $row['pill']->title }}">{{ $row['pill']->label }}</span>
                                @if($row['pill']->screenReaderText !== '')<span class="sr-only">{{ $row['pill']->screenReaderText }}</span>@endif
                            </td>
                            <td role="cell" class="roster-cell roster-cell--time"><span class="roster-cell__label" aria-hidden="true">From</span>{{ $row['from'] }}</td>
                            <td role="cell" class="roster-cell roster-cell--time"><span class="roster-cell__label" aria-hidden="true">Through</span>{{ $row['through'] }}</td>
                            <td role="cell" class="roster-cell roster-cell--time"><span class="roster-cell__label" aria-hidden="true">Hours</span>{{ $row['hours'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            @endforeach
        </table>
    </div>
</details>
