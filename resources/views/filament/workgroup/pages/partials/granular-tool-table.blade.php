{{-- Reusable granular tool ranking table partial.
     Receives: $items (array of scored product data from EvaluationService::getGranularToolGroupings)
     Each item has: product, name, brand, avg_score, response_count, meets_threshold,
                   capability_avg, usability_avg, affordability_avg, maintainability_avg, deployability_avg,
                   advance_yes, advance_no, deal_breakers
--}}
<div style="overflow-x: auto;">
    <table class="wg-table">
        <thead>
            <tr>
                <th style="width: 3rem; text-align: center;">#</th>
                <th>Product</th>
                <th style="text-align: center;">Overall</th>
                <th style="text-align: center;" class="wg-saver-s">S</th>
                <th style="text-align: center;" class="wg-saver-a">A</th>
                <th style="text-align: center;" class="wg-saver-v">V</th>
                <th style="text-align: center;" class="wg-saver-e">E</th>
                <th style="text-align: center;" class="wg-saver-r">R</th>
                <th style="text-align: center;">Advance</th>
                <th style="text-align: center;">Responses</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $idx => $item)
            @php
                $rank = $idx + 1;
                $medalBg = match($rank) {
                    1 => 'background-color: #C5A55A; color: #fff;',
                    2 => 'background-color: #A8A8A8; color: #fff;',
                    3 => 'background-color: #CD7F32; color: #fff;',
                    default => '',
                };
            @endphp
            <tr>
                <td style="text-align: center;">
                    @if($rank <= 3)
                    <span class="wg-rank-medal" style="width: 1.5rem; height: 1.5rem; font-size: 0.625rem; {{ $medalBg }}">{{ $rank }}</span>
                    @else
                    <span style="color: #A8A29E; font-size: 0.75rem;">{{ $rank }}</span>
                    @endif
                </td>
                <td>
                    <div style="font-weight: 600; color: #292524;">{{ $item['product']->name }}</div>
                    @if($item['brand'])
                    <div style="font-size: 0.6875rem; color: #A8A29E;">{{ $item['brand'] }}</div>
                    @endif
                </td>
                <td style="text-align: center;">
                    @if($item['avg_score'] !== null)
                    <span class="wg-score-badge {{ $item['avg_score'] >= 80 ? 'wg-score-badge--high' : ($item['avg_score'] >= 60 ? 'wg-score-badge--mid' : 'wg-score-badge--low') }}">
                        {{ number_format($item['avg_score'], 1) }}
                    </span>
                    @else
                    <span style="color: #D4D0CA;">—</span>
                    @endif
                </td>
                @foreach(['capability_avg', 'usability_avg', 'affordability_avg', 'maintainability_avg', 'deployability_avg'] as $ki => $saverKey)
                <td style="text-align: center; font-size: 0.75rem;" class="wg-score">
                    @if($item[$saverKey] ?? null)
                    <span class="{{ ['wg-saver-s','wg-saver-a','wg-saver-v','wg-saver-e','wg-saver-r'][$ki] }}">{{ number_format($item[$saverKey], 0) }}</span>
                    @else
                    <span style="color: #D4D0CA;">—</span>
                    @endif
                </td>
                @endforeach
                <td style="text-align: center;">
                    @if(($item['advance_yes'] ?? 0) > 0 || ($item['advance_no'] ?? 0) > 0)
                    <span style="font-size: 0.75rem;">
                        <span style="color: #059669; font-weight: 500;">{{ $item['advance_yes'] ?? 0 }}✓</span>
                        @if(($item['advance_no'] ?? 0) > 0)
                        <span style="color: #DC2626; margin-left: 0.25rem;">{{ $item['advance_no'] }}✕</span>
                        @endif
                    </span>
                    @if(($item['deal_breakers'] ?? 0) > 0)
                    <span style="margin-left: 0.25rem; font-size: 0.75rem; color: #DC2626;" title="{{ $item['deal_breakers'] }} deal-breaker(s)">⚠️</span>
                    @endif
                    @else
                    <span style="color: #D4D0CA; font-size: 0.75rem;">—</span>
                    @endif
                </td>
                <td style="text-align: center;">
                    <span class="wg-score" style="font-size: 0.75rem; padding: 0.125rem 0.5rem; border-radius: 9999px;
                        {{ $item['meets_threshold'] ? 'background-color: #EFF6FF; color: #1E40AF;' : 'background-color: #F5F3F0; color: #78716C;' }}">
                        {{ $item['response_count'] }}
                    </span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
