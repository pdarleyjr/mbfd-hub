<x-filament-panels::page>
    <div class="ep-req-layout">
        {{-- Left: Form --}}
        <div class="ep-req-form-col">
            <div class="ep-req-form-card">
                <div class="ep-req-form-header">
                    <div class="ep-req-form-icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="ep-req-form-title">New Equipment Request</h3>
                        <p class="ep-req-form-desc">Describe the gear, uniforms, or equipment you need. Be specific about sizes and quantities.</p>
                    </div>
                </div>
                <form wire:submit="submit" class="ep-req-form-body">
                    {{ $this->form }}
                    <div class="ep-req-form-submit">
                        <x-filament::button type="submit" size="lg" class="w-full ep-req-submit-btn">
                            Submit Request
                        </x-filament::button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Right: History --}}
        <div class="ep-req-history-col">
            <div class="ep-req-history-card">
                <div class="ep-req-history-header">
                    <span class="ep-req-history-title">Active Requests</span>
                    <span class="ep-req-history-count">{{ $history->count() }}</span>
                </div>
                @if($history->isEmpty())
                    <div class="ep-req-empty">
                        <svg class="ep-req-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>
                        </svg>
                        <p>No active requests. Submit your first request using the form.</p>
                    </div>
                @else
                    @foreach($history as $req)
                        <div class="ep-req-item">
                            <div class="ep-req-item-top">
                                <p class="ep-req-item-text">{{ Str::limit($req->requested_items, 100) }}</p>
                                <span class="ep-req-badge ep-req-badge-{{ strtolower(str_replace(' ', '-', $req->status)) }}">{{ $req->status }}</span>
                            </div>
                            <div class="ep-req-item-bottom">
                                <span class="ep-req-item-date">{{ $req->created_at->format('M j, Y') }}</span>
                                @if($req->reason)
                                    <span class="ep-req-item-note">Reason: {{ $req->reason }}</span>
                                @endif
                                @if($req->admin_notes)
                                    <span class="ep-req-item-note">{{ $req->admin_notes }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            {{-- Archived requests --}}
            @if($archived->isNotEmpty())
                <div class="ep-req-history-card" style="margin-top:0.75rem;opacity:0.8;">
                    <div class="ep-req-history-header">
                        <span class="ep-req-history-title">Request History (Archived)</span>
                        <span class="ep-req-history-count">{{ $archived->count() }}</span>
                    </div>
                    @foreach($archived as $req)
                        <div class="ep-req-item" style="opacity:0.75;">
                            <div class="ep-req-item-top">
                                <p class="ep-req-item-text">{{ Str::limit($req->requested_items, 100) }}</p>
                                <span class="ep-req-badge ep-req-badge-{{ strtolower(str_replace(' ', '-', $req->status)) }}">{{ $req->status }}</span>
                            </div>
                            <div class="ep-req-item-bottom">
                                <span class="ep-req-item-date">{{ $req->created_at->format('M j, Y') }}</span>
                                @if($req->reason) <span class="ep-req-item-note">{{ $req->reason }}</span> @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <x-filament-actions::modals />

    <style>
        .ep-req-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            align-items: start;
        }
        @media (max-width: 768px) {
            .ep-req-layout { grid-template-columns: 1fr; }
        }
        .ep-req-form-card {
            background: #ffffff;
            border: 1px solid #e8e5e0;
            border-radius: 0.875rem;
            overflow: hidden;
        }
        .ep-req-form-header {
            display: flex;
            align-items: flex-start;
            gap: 0.875rem;
            padding: 1.25rem;
            border-bottom: 1px solid #f0ede8;
            background: #fafaf8;
        }
        .ep-req-form-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.625rem;
            background: #fef2f2;
            color: #b91c1c;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .ep-req-form-title {
            font-size: 0.9375rem;
            font-weight: 700;
            color: #292524;
        }
        .ep-req-form-desc {
            font-size: 0.75rem;
            color: #78716c;
            margin-top: 0.25rem;
            line-height: 1.5;
        }
        .ep-req-form-body {
            padding: 1.25rem;
        }
        .ep-req-form-submit {
            margin-top: 1rem;
        }
        .ep-req-history-card {
            background: #ffffff;
            border: 1px solid #e8e5e0;
            border-radius: 0.875rem;
            overflow: hidden;
        }
        .ep-req-history-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.875rem 1.125rem;
            border-bottom: 1px solid #f0ede8;
            background: #fafaf8;
        }
        .ep-req-history-title {
            font-size: 0.75rem;
            font-weight: 700;
            color: #44403c;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .ep-req-history-count {
            font-size: 0.6875rem;
            color: #a8a29e;
        }
        .ep-req-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2.5rem 1.5rem;
            gap: 0.75rem;
            color: #a8a29e;
            text-align: center;
            font-size: 0.8125rem;
            line-height: 1.6;
        }
        .ep-req-empty-icon {
            width: 2rem;
            height: 2rem;
            color: #d4d0ca;
        }
        .ep-req-item {
            padding: 0.875rem 1.125rem;
            border-bottom: 1px solid #f8f6f2;
        }
        .ep-req-item:last-child { border-bottom: none; }
        .ep-req-item-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
        }
        .ep-req-item-text {
            font-size: 0.8125rem;
            color: #292524;
            line-height: 1.5;
            flex: 1;
        }
        .ep-req-item-bottom {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-top: 0.375rem;
        }
        .ep-req-item-date {
            font-size: 0.6875rem;
            color: #a8a29e;
        }
        .ep-req-item-note {
            font-size: 0.6875rem;
            color: #57534e;
            font-style: italic;
        }
        .ep-req-badge {
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
        .ep-req-badge-pending { background: #fffbeb; color: #92400e; }
        .ep-req-badge-approved { background: #f0fdf4; color: #166534; }
        .ep-req-badge-declined { background: #fef2f2; color: #991b1b; }
        .ep-req-badge-ready-for-pickup { background: #eff6ff; color: #1e40af; }
        .ep-req-badge-ordered { background: #f0f9ff; color: #0369a1; }
        .ep-req-badge-completed { background: #f0fdf4; color: #166534; }
    </style>
</x-filament-panels::page>
