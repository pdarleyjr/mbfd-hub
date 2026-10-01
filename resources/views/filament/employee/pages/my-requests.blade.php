<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
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
</x-filament-panels::page>
