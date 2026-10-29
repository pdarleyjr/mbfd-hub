<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee"
    x-init="window.matchMedia('(max-width: 1023px)').matches && $store.sidebar.close()">
    {{-- Stats Bar --}}
    <div class="ep-stats-bar">
        <div class="ep-stat">
            <span class="ep-stat-value">{{ $equipmentCount }}</span>
            <span class="ep-stat-label">Items Assigned</span>
        </div>
        <div class="ep-stat-divider"></div>
        <div class="ep-stat">
            <span class="ep-stat-value {{ $pendingRequests > 0 ? 'ep-stat-pending' : '' }}">{{ $pendingRequests }}</span>
            <span class="ep-stat-label">Pending Requests</span>
        </div>
    </div>

    @if($bidAssignment)
        <section class="ep-bid-assignment ep-panel" aria-labelledby="bid-assignment-heading" data-bid-assignment>
            <div class="ep-panel-header">
                <h2 id="bid-assignment-heading" class="ep-panel-title">{{ $bidAssignment->term_label }} {{ $bidAssignment->assignment_source === 'retained_nonbiddable' ? 'Assignment' : 'Bid Selection' }}</h2>
                <span class="ep-status-badge">{{ $bidAssignment->assignment_source === 'retained_nonbiddable' ? 'Retained assignment' : 'Bid award' }}</span>
            </div>
            <dl class="ep-bid-details">
                <div class="ep-bid-selection">
                    <dt>{{ $bidAssignment->assignment_source === 'retained_nonbiddable' ? 'Assignment' : 'Bid Selection' }}</dt>
                    <dd>{{ $bidAssignment->bid_selection_label }}</dd>
                </div>
                <div><dt>{{ $bidAssignment->assignment_source === 'retained_nonbiddable' ? 'Rank' : 'Rank at bid' }}</dt><dd>{{ $bidAssignment->rank_label }}</dd></div>
                @if($bidAssignment->position_label && $bidAssignment->position_label !== $bidAssignment->bid_selection_label && $bidAssignment->position_label !== $bidAssignment->rank_label)
                    <div><dt>Seat / Position</dt><dd>{{ $bidAssignment->position_label }}</dd></div>
                @endif
                <div><dt>Shift</dt><dd>{{ $bidAssignment->shift_label }}</dd></div>
                <div><dt>Station / Assignment Area</dt><dd>{{ $bidAssignment->station_label }}</dd></div>
                @if($bidAssignment->division_label)
                    <div><dt>Division</dt><dd>{{ $bidAssignment->division_label }}</dd></div>
                @endif
                <div><dt>A-Day</dt><dd>{{ $bidAssignment->a_day_label }}</dd></div>
            </dl>
        </section>
    @endif

    <h2 class="hub-portal-section-title">Your workspace</h2>
    {{-- Quick Actions --}}
    <div class="ep-actions-row">
        <a href="{{ \App\Filament\Employee\Pages\MyEquipmentPage::getUrl(panel: 'employee') }}" class="ep-action-card ep-action-primary">
            <div class="ep-action-icon ep-action-icon-blue">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.955 11.955 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                </svg>
            </div>
            <div class="ep-action-body">
                <span class="ep-action-title">My Equipment</span>
                <span class="ep-action-desc">View all assigned gear</span>
            </div>
            <svg class="ep-action-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
        <a href="{{ \App\Filament\Employee\Pages\RequestEquipmentPage::getUrl(panel: 'employee') }}" class="ep-action-card">
            <div class="ep-action-icon ep-action-icon-neutral">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
            </div>
            <div class="ep-action-body">
                <span class="ep-action-title">Request Uniforms</span>
                <span class="ep-action-desc">Request approved department workwear</span>
            </div>
            <svg class="ep-action-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
        @if ($canMessageLogistics)
        <a href="{{ \App\Filament\Employee\Pages\MyMessages::getUrl($messageUnread > 0 ? [] : ['compose' => 1], panel: 'employee') }}" class="ep-action-card emsg-dashboard-card" data-employee-messages-shortcut>
            <div class="ep-action-icon ep-action-icon-neutral">
                <x-heroicon-o-chat-bubble-bottom-center-text class="w-5 h-5" aria-hidden="true" />
            </div>
            <div class="ep-action-body">
                <span class="ep-action-title">Message Logistics</span>
                <span class="ep-action-desc">
                    @if ($messageUnread > 0)
                        {{ $messageUnread }} new {{ \Illuminate\Support\Str::plural('reply', $messageUnread) }} in My Messages
                    @else
                        Ask about equipment, uniforms or supplies
                    @endif
                </span>
            </div>
            @if ($messageUnread > 0)
                <span class="emsg-unread-dot">{{ $messageUnread }}<span class="sr-only"> unread</span></span>
            @endif
            <svg class="ep-action-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
        @endif
    </div>

    <nav class="ep-workspace-links" aria-label="Employee tools">
        <a href="{{ \App\Filament\Employee\Pages\MyRequestsPage::getUrl(panel: 'employee') }}">Request history</a>
        @if ($canMessageLogistics)
            <a href="{{ \App\Filament\Employee\Pages\MyMessages::getUrl(panel: 'employee') }}">My messages</a>
        @endif
        <a href="{{ \App\Filament\Employee\Pages\ApparatusServiceRequestPage::getUrl(panel: 'employee') }}">Apparatus service</a>
        <a href="{{ \App\Filament\Employee\Pages\OperationalForms::getUrl(panel: 'employee') }}">Operational forms</a>
        <a href="{{ \App\Filament\Employee\Pages\VideoConferencing::getUrl(panel: 'employee') }}">Video conferencing</a>
        <a href="{{ route('account.show') }}">My account</a>
    </nav>

    {{-- Two columns: Recent Equipment + Recent Requests --}}
    <div class="ep-two-col">
        {{-- Recent Equipment --}}
        <div class="ep-panel">
            <div class="ep-panel-header">
                <h2 class="ep-panel-title">Recently assigned</h2>
                <a href="{{ \App\Filament\Employee\Pages\MyEquipmentPage::getUrl(panel: 'employee') }}" class="ep-panel-link">View all →</a>
            </div>
            @if($recentEquipment->isEmpty())
                <div class="ep-empty">
                    <svg class="ep-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    <p>No equipment assigned yet</p>
                </div>
            @else
                @foreach($recentEquipment as $item)
                    <div class="ep-list-item">
                        <div class="ep-list-dot ep-dot-blue"></div>
                        <div class="ep-list-body">
                            <span class="ep-list-primary">{{ $item->item_description }}</span>
                            <span class="ep-list-secondary">{{ $item->category }}</span>
                        </div>
                        <span class="ep-list-meta tabular-nums">{{ $item->issued_at?->format('M j') ?? '—' }}</span>
                    </div>
                @endforeach
            @endif
        </div>

        {{-- Recent Requests --}}
        <div class="ep-panel">
            <div class="ep-panel-header">
                <h2 class="ep-panel-title">My requests</h2>
                <a href="{{ \App\Filament\Employee\Pages\MyRequestsPage::getUrl(panel: 'employee') }}" class="ep-panel-link">View all →</a>
            </div>
            @if($recentRequests->isEmpty())
                <div class="ep-empty">
                    <svg class="ep-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <p>No requests yet</p>
                </div>
            @else
                @foreach($recentRequests as $req)
                    <div class="ep-list-item">
                        <div class="ep-list-dot {{ match($req->status->value) {
                            'completed', 'ready_for_pickup' => 'ep-dot-green',
                            'pending', 'needs_information' => 'ep-dot-amber',
                            'denied', 'cancelled' => 'ep-dot-red',
                            'ordered', 'arrived', 'acknowledged' => 'ep-dot-blue',
                            default => 'ep-dot-gray'
                        } }}"></div>
                        <div class="ep-list-body">
                            <span class="ep-list-primary line-clamp-1">{{ Str::limit($req->items->pluck('item_name')->join(', '), 45) }}</span>
                            <span class="ep-list-secondary">{{ $req->created_at->copy()->timezone(config('hub.timezone'))->format('M j, Y') }}</span>
                        </div>
                        <span class="ep-status-badge">{{ $req->status->label() }}</span>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</x-filament-panels::page>
