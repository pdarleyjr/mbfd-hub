<x-filament-panels::page>
    {{-- Identity bar --}}
    <div class="ep-id-bar">
        <div class="ep-id-left">
            <span class="ep-id-name">{{ $user->name }}</span>
            <span class="ep-id-sep">·</span>
            <span class="ep-id-rank">{{ $user->rank ?? '' }}</span>
        </div>
        <div class="ep-id-right">
            <span class="ep-id-label">Employee ID</span>
            <span class="ep-id-number">{{ $user->employee_id ?? 'Not assigned' }}</span>
        </div>
    </div>

    @if($activeEquipment->isEmpty() && $history->isEmpty())
        <div class="ep-empty-full">
            <div class="ep-empty-inner">
                <svg class="ep-empty-big-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.955 11.955 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                </svg>
                <h3 class="ep-empty-heading">No equipment assigned yet</h3>
                <p class="ep-empty-body">Your assigned gear and uniforms will appear here once assigned by a logistics administrator.</p>
                <a href="{{ \App\Filament\Employee\Pages\RequestEquipmentPage::getUrl(panel: 'employee') }}" class="ep-empty-cta">
                    Request Uniforms →
                </a>
            </div>
        </div>
    @else
        {{-- Summary line --}}
        <div class="ep-eq-summary">
            <strong>{{ $activeEquipment->count() }}</strong> active item{{ $activeEquipment->count() === 1 ? '' : 's' }} across <strong>{{ $byCategory->count() }}</strong> {{ $byCategory->count() === 1 ? 'category' : 'categories' }}
            @if($expiringSoon->isNotEmpty()) · <strong>{{ $expiringSoon->count() }}</strong> expiring soon @endif
            @if($expired->isNotEmpty()) · <strong class="ep-expired-text">{{ $expired->count() }}</strong> expired @endif
        </div>

        @foreach($byCategory as $category => $items)
            <div class="ep-category">
                <div class="ep-category-header">
                    <span class="ep-category-name">{{ $category }}</span>
                    <span class="ep-category-count">{{ $items->count() }}</span>
                </div>
                <div class="ep-eq-table-wrap">
                    <table class="ep-eq-table">
                        <thead>
                            <tr>
                                <th class="ep-eq-th" style="width:40%">Item</th>
                                <th class="ep-eq-th ep-eq-th-right" style="width:15%">Qty</th>
                                <th class="ep-eq-th ep-eq-th-right" style="width:20%">Issued</th>
                                <th class="ep-eq-th ep-eq-th-right" style="width:25%">Expiration</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $item)
                                <tr class="ep-eq-row">
                                    <td class="ep-eq-td">{{ $item->item_description }}</td>
                                    <td class="ep-eq-td ep-eq-td-right ep-eq-qty">{{ $item->quantity }}</td>
                                    <td class="ep-eq-td ep-eq-td-right ep-eq-date">
                                        {{ $item->issued_at ? $item->issued_at->format('M j, Y') : '—' }}
                                    </td>
                                    <td class="ep-eq-td ep-eq-td-right ep-eq-date">
                                        @if(!$item->expires_at)
                                            —
                                        @elseif($item->expires_at->isBefore(today()))
                                            <span class="ep-expiration ep-expiration-expired">Expired · {{ $item->expires_at->format('M j, Y') }}</span>
                                        @elseif($item->expires_at->lte(today()->addDays(60)))
                                            <span class="ep-expiration ep-expiration-soon">Expiring Soon · {{ $item->expires_at->format('M j, Y') }}</span>
                                        @else
                                            {{ $item->expires_at->format('M j, Y') }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        @if($history->isNotEmpty())
            <div class="ep-category">
                <div class="ep-category-header"><span class="ep-category-name">Returned / Retired History</span><span class="ep-category-count">{{ $history->count() }}</span></div>
                <div class="ep-eq-table-wrap">
                    @foreach($history as $item)
                        <div class="ep-history-row"><span><strong>{{ $item->item_description }}</strong><small>{{ $item->category }}</small></span><span>{{ $item->returned_at?->format('M j, Y') ?? str($item->status)->title() }}</span></div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    <style>
        .ep-id-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.875rem 1.25rem;
            background: #292524;
            border-radius: 0.75rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .ep-id-left {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .ep-id-name {
            font-size: 1rem;
            font-weight: 700;
            color: #ffffff;
        }
        .ep-id-sep { color: #57534e; }
        .ep-id-rank {
            font-size: 0.8125rem;
            color: #fca5a5;
            font-weight: 500;
        }
        .ep-id-right {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .ep-id-label {
            font-size: 0.6875rem;
            color: #78716c;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .ep-id-number {
            font-size: 0.875rem;
            font-weight: 700;
            color: #d4d4d4;
            font-variant-numeric: tabular-nums;
            background: rgba(255,255,255,0.08);
            padding: 0.125rem 0.5rem;
            border-radius: 0.375rem;
        }
        .ep-eq-summary {
            font-size: 0.8125rem;
            color: #78716c;
            margin-bottom: 1rem;
        }
        .ep-empty-full {
            display: flex;
            justify-content: center;
            padding: 3rem 1rem;
        }
        .ep-empty-inner {
            text-align: center;
            max-width: 22rem;
        }
        .ep-empty-big-icon {
            width: 3rem;
            height: 3rem;
            color: #d4d0ca;
            margin: 0 auto 1rem;
        }
        .ep-empty-heading {
            font-size: 1rem;
            font-weight: 700;
            color: #292524;
            margin-bottom: 0.5rem;
        }
        .ep-empty-body {
            font-size: 0.875rem;
            color: #78716c;
            line-height: 1.6;
            margin-bottom: 1.25rem;
        }
        .ep-empty-cta {
            display: inline-flex;
            align-items: center;
            padding: 0.625rem 1.25rem;
            background: #b91c1c;
            color: #ffffff;
            border-radius: 0.625rem;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            transition: background 150ms;
        }
        .ep-empty-cta:hover { background: #991b1b; }
        .ep-category {
            margin-bottom: 1.25rem;
        }
        .ep-category-header {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            margin-bottom: 0.5rem;
        }
        .ep-category-name {
            font-size: 0.75rem;
            font-weight: 700;
            color: #78716c;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .ep-category-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.375rem;
            height: 1.375rem;
            border-radius: 50%;
            background: #f0ede8;
            font-size: 0.625rem;
            font-weight: 700;
            color: #78716c;
        }
        .ep-eq-table-wrap {
            background: #ffffff;
            border: 1px solid #e8e5e0;
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .ep-eq-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
        }
        .ep-eq-th {
            padding: 0.625rem 1rem;
            font-size: 0.6875rem;
            font-weight: 700;
            color: #78716c;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: #f8f6f2;
            border-bottom: 1px solid #e8e5e0;
            text-align: left;
        }
        .ep-eq-th-right { text-align: right; }
        .ep-eq-row {
            border-bottom: 1px solid #f0ede8;
            transition: background 120ms;
        }
        .ep-eq-row:last-child { border-bottom: none; }
        .ep-eq-row:hover { background: #fafaf8; }
        .ep-eq-td {
            padding: 0.75rem 1rem;
            color: #292524;
        }
        .ep-eq-td-right { text-align: right; }
        .ep-expiration{display:inline-flex;border-radius:999px;padding:.2rem .45rem;font-size:.65rem;font-weight:800}.ep-expiration-soon{background:#fef3c7;color:#92400e}.ep-expiration-expired{background:#fee2e2;color:#991b1b}.ep-expired-text{color:#b91c1c}.ep-history-row{display:flex;min-height:3.5rem;align-items:center;justify-content:space-between;gap:1rem;padding:.75rem 1rem;border-bottom:1px solid #f0ede8;color:#57534e;font-size:.78rem}.ep-history-row:last-child{border:0}.ep-history-row strong,.ep-history-row small{display:block}.ep-history-row strong{color:#292524}.ep-history-row small{margin-top:.15rem;color:#78716c}
        .ep-eq-qty {
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            color: #292524;
        }
        .ep-eq-date {
            color: #78716c;
            font-variant-numeric: tabular-nums;
        }
    </style>
</x-filament-panels::page>
