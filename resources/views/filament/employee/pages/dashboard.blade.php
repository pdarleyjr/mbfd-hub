<x-filament-panels::page>
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
            <div class="ep-action-icon ep-action-icon-amber">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
            </div>
            <div class="ep-action-body">
                <span class="ep-action-title">Request Equipment</span>
                <span class="ep-action-desc">Submit a gear request</span>
            </div>
            <svg class="ep-action-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    </div>

    {{-- Two columns: Recent Equipment + Recent Requests --}}
    <div class="ep-two-col">
        {{-- Recent Equipment --}}
        <div class="ep-panel">
            <div class="ep-panel-header">
                <span class="ep-panel-title">Recently Assigned</span>
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
                <span class="ep-panel-title">My Requests</span>
                <a href="{{ \App\Filament\Employee\Pages\RequestEquipmentPage::getUrl(panel: 'employee') }}" class="ep-panel-link">Submit new →</a>
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
                        <div class="ep-list-dot {{ match($req->status) {
                            'Approved' => 'ep-dot-green',
                            'Pending' => 'ep-dot-amber',
                            'Declined' => 'ep-dot-red',
                            'Ordered' => 'ep-dot-blue',
                            default => 'ep-dot-gray'
                        } }}"></div>
                        <div class="ep-list-body">
                            <span class="ep-list-primary line-clamp-1">{{ Str::limit($req->requested_items, 45) }}</span>
                            <span class="ep-list-secondary">{{ $req->created_at->format('M j, Y') }}</span>
                        </div>
                        <span class="ep-status-badge ep-status-{{ strtolower($req->status) }}">{{ $req->status }}</span>
                    </div>
                @endforeach
            @endif
        </div>
    </div>

    <style>
        /* ── Employee Portal Design System ── */
        .ep-hero {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.25rem 1.5rem;
            background: #292524;
            border-radius: 0.875rem;
            margin-bottom: 1rem;
            color: #ffffff;
        }
        .ep-hero-badge {
            width: 3rem;
            height: 3rem;
            border-radius: 50%;
            background: rgba(220, 38, 38, 0.2);
            border: 1.5px solid rgba(220, 38, 38, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .ep-hero-badge-icon {
            width: 1.5rem;
            height: 1.5rem;
            color: #fca5a5;
        }
        .ep-hero-info { flex: 1; min-width: 0; }
        .ep-hero-name {
            font-size: 1.125rem;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.3;
        }
        .ep-hero-meta {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            margin-top: 0.25rem;
        }
        .ep-hero-rank {
            font-size: 0.8125rem;
            color: #fca5a5;
            font-weight: 600;
        }
        .ep-hero-sep {
            color: #78716c;
            font-size: 0.75rem;
        }
        .ep-hero-id {
            font-size: 0.8125rem;
            color: #a8a29e;
            font-variant-numeric: tabular-nums;
        }
        /* Stats bar */
        .ep-stats-bar {
            display: flex;
            align-items: center;
            gap: 0;
            background: #ffffff;
            border: 1px solid #e8e5e0;
            border-radius: 0.75rem;
            overflow: hidden;
            margin-bottom: 1rem;
        }
        .ep-stat {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1rem 1.5rem;
        }
        .ep-stat-divider {
            width: 1px;
            height: 2.5rem;
            background: #e8e5e0;
        }
        .ep-stat-value {
            font-size: 1.875rem;
            font-weight: 800;
            color: #292524;
            font-variant-numeric: tabular-nums;
            line-height: 1;
        }
        .ep-stat-pending { color: #d97706; }
        .ep-stat-label {
            font-size: 0.75rem;
            color: #78716c;
            font-weight: 500;
            margin-top: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        /* Action cards */
        .ep-actions-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        @media (max-width: 640px) {
            .ep-actions-row { grid-template-columns: 1fr; }
        }
        .ep-action-card {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            padding: 1rem 1.125rem;
            background: #ffffff;
            border: 1px solid #e8e5e0;
            border-radius: 0.75rem;
            text-decoration: none;
            transition: border-color 150ms, box-shadow 150ms;
        }
        .ep-action-card:hover {
            border-color: #d4d0ca;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.06);
        }
        .ep-action-primary { border-left: 3px solid #2563eb; }
        .ep-action-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.625rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .ep-action-icon-blue { background: #eff6ff; color: #2563eb; }
        .ep-action-icon-amber { background: #fffbeb; color: #d97706; }
        .ep-action-body {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .ep-action-title {
            font-size: 0.9375rem;
            font-weight: 600;
            color: #292524;
        }
        .ep-action-desc {
            font-size: 0.75rem;
            color: #78716c;
            margin-top: 0.125rem;
        }
        .ep-action-arrow {
            width: 1rem;
            height: 1rem;
            color: #d4d0ca;
            flex-shrink: 0;
        }
        /* Two-column panels */
        .ep-two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }
        @media (max-width: 768px) {
            .ep-two-col { grid-template-columns: 1fr; }
        }
        .ep-panel {
            background: #ffffff;
            border: 1px solid #e8e5e0;
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .ep-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.875rem 1.125rem;
            border-bottom: 1px solid #f0ede8;
            background: #fafaf8;
        }
        .ep-panel-title {
            font-size: 0.8125rem;
            font-weight: 700;
            color: #44403c;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .ep-panel-link {
            font-size: 0.75rem;
            color: #b91c1c;
            text-decoration: none;
            font-weight: 500;
        }
        .ep-panel-link:hover { text-decoration: underline; }
        .ep-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2rem 1rem;
            color: #a8a29e;
            font-size: 0.8125rem;
            gap: 0.5rem;
        }
        .ep-empty-icon {
            width: 2rem;
            height: 2rem;
            color: #d4d0ca;
        }
        /* List items */
        .ep-list-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.125rem;
            border-bottom: 1px solid #f8f6f2;
        }
        .ep-list-item:last-child { border-bottom: none; }
        .ep-list-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .ep-dot-blue { background: #2563eb; }
        .ep-dot-green { background: #16a34a; }
        .ep-dot-amber { background: #d97706; }
        .ep-dot-red { background: #dc2626; }
        .ep-dot-gray { background: #a8a29e; }
        .ep-list-body {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }
        .ep-list-primary {
            font-size: 0.8125rem;
            font-weight: 600;
            color: #292524;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ep-list-secondary {
            font-size: 0.6875rem;
            color: #a8a29e;
            margin-top: 0.125rem;
        }
        .ep-list-meta {
            font-size: 0.6875rem;
            color: #a8a29e;
            flex-shrink: 0;
        }
        /* Status badges */
        .ep-status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.625rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            flex-shrink: 0;
        }
        .ep-status-pending { background: #fffbeb; color: #92400e; }
        .ep-status-approved { background: #f0fdf4; color: #166534; }
        .ep-status-declined { background: #fef2f2; color: #991b1b; }
        .ep-status-ordered { background: #eff6ff; color: #1e40af; }
    </style>
</x-filament-panels::page>
