<tbody role="rowgroup" class="roster-seat" data-roster-seat data-seat-index="{{ $seatIndex }}">
    @foreach($seat as $row)
        <tr role="row" class="roster-row @if($row['isVacant']) roster-row--vacant @endif" data-roster-row>
            <td role="cell" class="roster-cell roster-cell--rank">
                @if($row['isFirstInSeat'])
                    {{ $row['rank'] }}
                @else
                    <span class="roster-rank-continued">{{ $row['rank'] }}<span class="sr-only">, same seat</span></span>
                @endif
            </td>
            <td role="cell" class="roster-cell roster-cell--id">{{ $row['employeeId'] }}</td>
            <td role="cell" class="roster-cell roster-cell--member">
                @if(! $row['isVacant'] && $row['employeeId'] !== '')
                    <button type="button" class="roster-member__name roster-member__name--link" data-roster-member="{{ $row['employeeId'] }}" aria-haspopup="dialog">{{ $row['name'] }}</button>
                @else
                    <span class="roster-member__name">{{ $row['name'] }}</span>
                @endif
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
