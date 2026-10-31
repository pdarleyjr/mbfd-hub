<ul class="roster-legend__list" data-roster-legend-items>
    @foreach($legend as $pill)
        <li>
            <span class="hub-tag roster-pill roster-pill--{{ $pill->tone }}">{{ $pill->label }}</span>
            <span class="roster-legend__meaning">{{ $pill->meaning }}</span>
        </li>
    @endforeach
</ul>
