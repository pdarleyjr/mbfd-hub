<x-filament-panels::page>
    @php
        $assignments = \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelAssignmentResource::class;
        $sections = [['Active Uniforms', $activeUniforms], ['Active Firefighting Equipment', $activeEquipment], ['Expiring Soon', $expiringSoon], ['Expired', $expired], ['History', $history]];
    @endphp
    <div class="pu-record" data-hub-ui="2">
        <div class="pu-intro">
            <div>
                <p class="pu-eyebrow">Personnel equipment record</p>
                <h2>{{ $employee->name }}</h2>
                <p>{{ $employee->rank }} · Employee ID {{ $employee->employee_id }}</p>
            </div>
        </div>

        <div class="pu-record-grid">
            @foreach($sections as [$title, $items])
                <section class="pu-panel {{ $title === 'Expired' && $items->isNotEmpty() ? 'pu-panel--attention' : '' }}" aria-label="{{ $title }}">
                    <header><h3>{{ $title }}</h3><span class="pu-count">{{ $items->count() }}</span></header>
                    @forelse($items as $item)
                        <a href="{{ $assignments::getUrl('edit', ['record' => $item]) }}" class="pu-row">
                            <span><strong>{{ $item->item_description }}</strong><small>{{ $item->category }} · Qty {{ $item->quantity }}</small></span>
                            <span class="pu-meta">{{ $item->expires_at ? 'Expires '.$item->expires_at->format('M j, Y') : ($item->issued_at ? 'Issued '.$item->issued_at->format('M j, Y') : 'No issue date') }}</span>
                        </a>
                    @empty
                        <p class="pu-empty">No records in this section.</p>
                    @endforelse
                </section>
            @endforeach
        </div>

        <section class="pu-panel" aria-label="Requests">
            <header><h3>Requests</h3><span class="pu-count">{{ $requests->count() + $legacyRequests->count() }}</span></header>
            @foreach($requests as $request)
                <a href="{{ \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource::getUrl('view', ['record' => $request]) }}" class="pu-row">
                    <span><strong>{{ $request->request_number }} · {{ $request->type->label() }}</strong><small>{{ $request->items->pluck('item_name')->join(', ') }}</small></span>
                    <span class="{{ $request->status->tagClass() }}">{{ $request->status->label() }}</span>
                </a>
            @endforeach
            @foreach($legacyRequests as $legacy)
                <a href="/admin/employee-equipment-requests/{{ $legacy->id }}" class="pu-row">
                    <span><strong>Legacy request · {{ $legacy->created_at->format('M j, Y') }}</strong><small>{{ \Illuminate\Support\Str::limit($legacy->requested_items, 100) }}</small></span>
                    <span class="hub-tag">{{ $legacy->status }}</span>
                </a>
            @endforeach
            @if($requests->isEmpty() && $legacyRequests->isEmpty())
                <p class="pu-empty">No request history.</p>
            @endif
        </section>
    </div>
</x-filament-panels::page>
