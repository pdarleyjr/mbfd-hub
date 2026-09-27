<x-filament-panels::page>
    <div class="mr-intro">
        <div><span>REQUEST LEDGER</span><h2>Uniform and personnel equipment requests</h2><p>This timeline includes uniforms you requested and firefighting equipment submitted for you by an officer.</p></div>
        <a href="{{ \App\Filament\Employee\Pages\RequestEquipmentPage::getUrl(panel: 'employee') }}">Request Uniforms</a>
    </div>

    <div class="mr-list">
        @forelse($requests as $request)
            <a href="/employee/my-requests/{{ $request->public_id }}" class="mr-row">
                <div class="mr-rail {{ $request->type->value === 'equipment' ? 'mr-rail-ppe' : '' }}"></div>
                <div class="mr-main">
                    <div class="mr-top"><strong>{{ $request->request_number }}</strong><span>{{ $request->status->label() }}</span></div>
                    <h3>{{ $request->type->label() }}</h3>
                    <p>{{ $request->items->pluck('item_name')->join(', ') }}</p>
                    <small>Submitted by {{ $request->requester_rank }} {{ $request->requester_name }} · {{ $request->created_at->format('M j, Y') }}</small>
                </div>
                <div class="mr-arrow" aria-hidden="true">→</div>
            </a>
        @empty
            <div class="mr-empty"><strong>No personnel requests yet</strong><p>Your structured uniform and officer-submitted equipment requests will appear here.</p></div>
        @endforelse
    </div>
    <div class="mt-4">{{ $requests->links() }}</div>

    @if($legacyRequests->isNotEmpty())
        <details class="mr-legacy">
            <summary>Historical legacy requests ({{ $legacyRequests->count() }})</summary>
            @foreach($legacyRequests as $legacy)
                <div><strong>{{ $legacy->status }}</strong><p>{{ $legacy->requested_items }}</p><small>{{ $legacy->created_at->format('M j, Y') }}</small></div>
            @endforeach
        </details>
    @endif

    <style>
        .mr-intro{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1rem;padding:1.4rem;border-radius:1rem;background:rgb(var(--hub-header));color:#fff}.mr-intro span{font-size:.7rem;font-weight:800;letter-spacing:.12em;color:#93c5fd}.mr-intro h2{margin:.35rem 0 0;font-size:1.25rem;font-weight:800}.mr-intro p{margin:.45rem 0 0;color:#cbd5e1;font-size:.875rem}.mr-intro a{display:inline-flex;min-height:3rem;align-items:center;flex-shrink:0;border-radius:.75rem;background:rgb(var(--hub-blue));padding:.65rem 1rem;color:#fff;font-weight:800}.mr-intro a:hover{background:rgb(var(--hub-blue-strong))}.mr-list{overflow:hidden;border:1px solid rgb(var(--hub-border));border-radius:1rem;background:#fff}.mr-row{display:grid;grid-template-columns:.35rem 1fr auto;min-height:7rem;border-bottom:1px solid rgb(var(--hub-border));text-decoration:none}.mr-row:last-child{border:0}.mr-row:hover{background:rgb(var(--hub-canvas))}.mr-rail{background:rgb(var(--hub-blue))}.mr-rail-ppe{background:rgb(var(--hub-blue))}.mr-main{padding:1rem 1.2rem}.mr-top{display:flex;align-items:center;justify-content:space-between;gap:1rem}.mr-top strong{color:rgb(var(--hub-ink))}.mr-top span{border-radius:999px;background:#dbeafe;padding:.25rem .6rem;color:#1e3a8a;font-size:.7rem;font-weight:800}.mr-main h3{margin:.45rem 0 0;color:rgb(var(--hub-ink));font-size:.9rem;font-weight:800}.mr-main p{margin:.25rem 0;color:rgb(var(--hub-ink-secondary));font-size:.85rem}.mr-main small{color:rgb(var(--hub-muted))}.mr-arrow{display:flex;align-items:center;padding:1rem;color:rgb(var(--hub-blue));font-size:1.2rem}.mr-empty{padding:3rem 1.5rem;text-align:center;color:rgb(var(--hub-ink-secondary))}.mr-empty strong{font-size:1rem;color:rgb(var(--hub-ink))}.mr-empty p{margin:.4rem 0}.mr-legacy{margin-top:1rem;border:1px solid rgb(var(--hub-border));border-radius:1rem;background:rgb(var(--hub-canvas));padding:1rem}.mr-legacy summary{min-height:3rem;cursor:pointer;font-weight:800}.mr-legacy div{padding:1rem 0;border-top:1px solid rgb(var(--hub-border))}.mr-legacy p{margin:.25rem 0;color:rgb(var(--hub-ink-secondary))}@media(max-width:640px){.mr-intro{flex-direction:column}.mr-intro a{width:100%;justify-content:center}.mr-top{align-items:flex-start;flex-direction:column;gap:.35rem}.mr-row{min-height:8rem}}
    </style>
</x-filament-panels::page>
