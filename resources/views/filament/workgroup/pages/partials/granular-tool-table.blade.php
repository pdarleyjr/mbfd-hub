{{-- Reusable granular tool ranking table partial.
     Receives: $items (array of scored product data from EvaluationService::getGranularToolGroupings)
     Each item has: product, name, brand, avg_score, response_count, meets_threshold,
                   capability_avg, usability_avg, affordability_avg, maintainability_avg, deployability_avg,
                   advance_yes, advance_no, deal_breakers
     Styles: .wg-gtt-* in resources/css/filament/workgroup-pages.css (tokens only).
--}}
<div class="wg-gtt" tabindex="0" role="region" aria-label="Tool rankings">
    <table data-hub-table class="wg-table">
        <thead>
            <tr>
                <th class="wg-gtt-rank">#</th>
                <th>Product</th>
                <th class="wg-gtt-center">Overall</th>
                <th class="wg-gtt-center wg-saver-s">S</th>
                <th class="wg-gtt-center wg-saver-a">A</th>
                <th class="wg-gtt-center wg-saver-v">V</th>
                <th class="wg-gtt-center wg-saver-e">E</th>
                <th class="wg-gtt-center wg-saver-r">R</th>
                <th class="wg-gtt-center">Advance</th>
                <th class="wg-gtt-center">Responses</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $idx => $item)
            @php
                $rank = $idx + 1;
                $medal = match($rank) {
                    1 => 'wg-gtt-medal--gold',
                    2 => 'wg-gtt-medal--silver',
                    3 => 'wg-gtt-medal--bronze',
                    default => '',
                };
            @endphp
            <tr>
                <td class="wg-gtt-center">
                    @if($rank <= 3)
                    <span class="wg-rank-medal wg-gtt-medal {{ $medal }}">{{ $rank }}</span>
                    @else
                    <span class="wg-gtt-meta">{{ $rank }}</span>
                    @endif
                </td>
                <td>
                    <div class="wg-gtt-product">{{ $item['product']->name }}</div>
                    @if($item['brand'])
                    <div class="wg-gtt-brand">{{ $item['brand'] }}</div>
                    @endif
                </td>
                <td class="wg-gtt-center">
                    @if($item['avg_score'] !== null)
                    <span class="wg-score-badge {{ $item['avg_score'] >= 80 ? 'wg-score-badge--high' : ($item['avg_score'] >= 60 ? 'wg-score-badge--mid' : 'wg-score-badge--low') }}">
                        {{ number_format($item['avg_score'], 1) }}
                    </span>
                    @else
                    <span class="wg-gtt-empty">—</span>
                    @endif
                </td>
                @foreach(['capability_avg', 'usability_avg', 'affordability_avg', 'maintainability_avg', 'deployability_avg'] as $ki => $saverKey)
                <td class="wg-gtt-center wg-gtt-small wg-score">
                    @if($item[$saverKey] ?? null)
                    <span class="{{ ['wg-saver-s','wg-saver-a','wg-saver-v','wg-saver-e','wg-saver-r'][$ki] }}">{{ number_format($item[$saverKey], 0) }}</span>
                    @else
                    <span class="wg-gtt-empty">—</span>
                    @endif
                </td>
                @endforeach
                <td class="wg-gtt-center">
                    @if(($item['advance_yes'] ?? 0) > 0 || ($item['advance_no'] ?? 0) > 0)
                    <span class="wg-gtt-small">
                        <span class="wg-gtt-yes">{{ $item['advance_yes'] ?? 0 }}✓</span>
                        @if(($item['advance_no'] ?? 0) > 0)
                        <span class="wg-gtt-no">{{ $item['advance_no'] }}✕</span>
                        @endif
                    </span>
                    @if(($item['deal_breakers'] ?? 0) > 0)
                    <span class="wg-gtt-small wg-gtt-no" title="{{ $item['deal_breakers'] }} deal-breaker(s)">⚠️</span>
                    @endif
                    @else
                    <span class="wg-gtt-empty wg-gtt-small">—</span>
                    @endif
                </td>
                <td class="wg-gtt-center">
                    <span class="wg-score wg-gtt-count {{ $item['meets_threshold'] ? 'wg-gtt-count--met' : '' }}">
                        {{ $item['response_count'] }}
                    </span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
