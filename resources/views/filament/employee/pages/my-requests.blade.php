<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <div class="mr-toolbar">
        <p>Uniform and personnel equipment requests, including firefighting equipment an officer submitted for you.</p>
        <a href="{{ \App\Filament\Employee\Pages\RequestEquipmentPage::getUrl(panel: 'employee') }}">Request Uniforms</a>
    </div>

    <div class="mr-list">
        @forelse($requests as $request)
            <a href="/employee/my-requests/{{ $request->public_id }}" class="mr-row">
                <div class="mr-main">
                    <div class="mr-top"><strong>{{ $request->request_number }}</strong><span class="{{ $request->status->tagClass() }}">{{ $request->status->label() }}</span></div>
                    <h3>{{ $request->type->label() }}</h3>
                    <p>{{ $request->items->pluck('item_name')->join(', ') }}</p>
                    <small>Submitted by {{ $request->requester_rank }} {{ $request->requester_name }} · {{ $request->created_at->format('M j, Y') }}</small>
                </div>
                <div class="mr-arrow" aria-hidden="true">→</div>
            </a>
        @empty
            <div class="mr-empty hub-empty"><x-heroicon-o-inbox class="hub-empty__icon" aria-hidden="true" /><p class="hub-empty__title">No personnel requests yet</p><p class="hub-empty__body">Your structured uniform and officer-submitted equipment requests will appear here.</p></div>
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
