<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
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
                    <h2 class="ep-category-name">{{ $category }}</h2>
                    <span class="ep-category-count">{{ $items->count() }}</span>
                </div>
                <div class="ep-eq-table-wrap">
                    <table class="ep-eq-table" role="table">
                        <caption class="hub-visually-hidden">Assigned {{ $category }} equipment</caption>
                        <thead role="rowgroup">
                            <tr role="row">
                                <th scope="col" role="columnheader" class="ep-eq-th">Item</th>
                                <th scope="col" role="columnheader" class="ep-eq-th ep-eq-th-right">Qty</th>
                                <th scope="col" role="columnheader" class="ep-eq-th ep-eq-th-right">Issued</th>
                                <th scope="col" role="columnheader" class="ep-eq-th ep-eq-th-right">Expiration</th>
                            </tr>
                        </thead>
                        <tbody role="rowgroup">
                            @foreach($items as $item)
                                <tr role="row" class="ep-eq-row">
                                    <td role="cell" class="ep-eq-td" data-label="Item">{{ $item->item_description }}</td>
                                    <td role="cell" class="ep-eq-td ep-eq-td-right ep-eq-qty" data-label="Quantity">{{ $item->quantity }}</td>
                                    <td role="cell" class="ep-eq-td ep-eq-td-right ep-eq-date" data-label="Issued">
                                        {{ $item->issued_at ? $item->issued_at->format('M j, Y') : '—' }}
                                    </td>
                                    <td role="cell" class="ep-eq-td ep-eq-td-right ep-eq-date" data-label="Expiration">
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
</x-filament-panels::page>
