<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    {{-- Hero Identity Strip --}}
    <div class="ep-hero">
        <div class="ep-hero-badge">
            <svg class="ep-hero-badge-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
            </svg>
        </div>
        <div class="ep-hero-info">
            <h2 class="ep-hero-name">{{ $user->name }}</h2>
            <div class="ep-hero-meta">
                @if($user->rank)
                    <span class="ep-hero-rank">{{ $user->rank }}</span>
                    <span class="ep-hero-sep">·</span>
                @endif
                <span class="ep-hero-id">
                    ID: {{ $user->employee_id ?? 'Not assigned' }}
                </span>
            </div>
        </div>
        <a href="/" class="ep-home-btn" title="Return to MBFD Hub" aria-label="Return to MBFD Hub home">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/>
            </svg>
            <span>Hub Home</span>
        </a>
    </div>

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
    </div>

    <nav class="ep-workspace-links" aria-label="Employee tools">
        <a href="{{ \App\Filament\Employee\Pages\MyRequestsPage::getUrl(panel: 'employee') }}">Request history</a>
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
                            <span class="ep-list-secondary">{{ $req->created_at->format('M j, Y') }}</span>
                        </div>
                        <span class="ep-status-badge">{{ $req->status->label() }}</span>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</x-filament-panels::page>
