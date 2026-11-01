<article class="roster-card" data-roster-card>
    <header class="roster-card__header">
        <h2 class="roster-card__name">{{ $card['name'] }}</h2>
        <p class="roster-card__identity">
            @if($card['rank'] !== '')<span>{{ $card['rank'] }}</span>@endif
            <span>ID {{ $card['employeeId'] }}</span>
        </p>
    </header>

    <section class="roster-card__section">
        <h3 class="roster-card__heading">On the roster for {{ $card['rosterDate'] }}</h3>
        <ul class="roster-card__rows">
            @foreach($card['rows'] as $row)
                <li class="roster-card__row">
                    <span class="roster-card__unit">{{ $row['unit'] }}</span>
                    <span class="hub-tag roster-pill roster-pill--{{ $row['pill']->tone }}" title="{{ $row['pill']->title }}">{{ $row['pill']->label }}</span>
                    <span class="roster-card__detail">{{ $row['seat'] }}@if($row['station'] !== '' && $row['station'] !== $row['unit']) · {{ $row['station'] }}@endif</span>
                    <span class="roster-card__detail roster-card__times">{{ $row['from'] }} to {{ $row['through'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="roster-card__section">
        <h3 class="roster-card__heading">TeleStaff certifications</h3>
        @if($card['certifications'] !== [])
            <ul class="roster-card__certifications">
                @foreach($card['certifications'] as $certification)
                    <li class="hub-tag">{{ $certification }}</li>
                @endforeach
            </ul>
        @else
            <p class="roster-card__empty">None listed on this roster.</p>
        @endif
    </section>

    @if($card['hasProfile'])
        <section class="roster-card__section">
            <h3 class="roster-card__heading">Current bid</h3>
            @if($card['bid'] !== [])
                <dl class="roster-card__facts">
                    @foreach($card['bid'] as $label => $value)
                        <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            @else
                <p class="roster-card__empty">No bid result for the current term.</p>
            @endif
        </section>

        @if($card['station'] !== '' || $card['email'] !== '')
            <section class="roster-card__section">
                <h3 class="roster-card__heading">Hub profile</h3>
                <dl class="roster-card__facts">
                    @if($card['station'] !== '')<div><dt>Station</dt><dd>{{ $card['station'] }}</dd></div>@endif
                    @if($card['email'] !== '')<div><dt>Email</dt><dd><a href="mailto:{{ $card['email'] }}">{{ $card['email'] }}</a></dd></div>@endif
                </dl>
            </section>
        @endif
    @else
        <p class="roster-card__empty">No Hub profile is linked to this ID, so only what TeleStaff prints is shown.</p>
    @endif
</article>
