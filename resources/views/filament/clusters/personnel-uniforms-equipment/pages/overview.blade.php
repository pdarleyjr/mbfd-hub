<x-filament-panels::page>
    @php $requests = \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource::class; @endphp
    <div class="pu-overview" data-hub-ui="2">
        <div class="pu-intro">
            <div>
                <p class="pu-eyebrow">Support Services · Chain of custody</p>
                <h2>Personally issued uniforms and firefighting equipment</h2>
                <p>One queue from officer or member submission through review, ordering, arrival, pickup, fulfillment, assignment, and expiration.</p>
            </div>
            <a class="hub-btn hub-btn--primary" href="{{ $requests::getUrl('index') }}">Manage all requests</a>
        </div>

        <div class="pu-workstreams">
            @foreach([['Uniform Requests', $uniform, $recentUniform, 'uniform'], ['Equipment Requests', $equipment, $recentEquipment, 'equipment']] as [$title, $stats, $recent, $type])
                <section class="pu-panel" aria-labelledby="pu-stream-{{ $type }}">
                    <header>
                        <div><p class="pu-eyebrow">{{ ucfirst($type) }} workflow</p><h3 id="pu-stream-{{ $type }}">{{ $title }}</h3></div>
                        <a href="{{ $requests::getUrl('index', ['tableFilters' => ['type' => ['value' => $type]]]) }}">Manage {{ strtolower($title) }}</a>
                    </header>
                    <div class="pu-metrics">
                        <div><strong>{{ $stats['open'] }}</strong><small>Open</small></div>
                        <div><strong>{{ $stats['needs_information'] }}</strong><small>Needs info</small></div>
                        <div><strong>{{ $stats['ordered'] }}</strong><small>Ordered</small></div>
                        <div><strong>{{ $type === 'equipment' ? $stats['arrived'] : $stats['ready'] }}</strong><small>{{ $type === 'equipment' ? 'Arrived' : 'Ready' }}</small></div>
                    </div>
                    <div>
                        @forelse($recent as $request)
                            <a class="pu-row" href="{{ $requests::getUrl('view', ['record' => $request]) }}">
                                <span><strong>{{ $request->request_number }}</strong><small>{{ $request->beneficiary_name }}</small></span>
                                <span class="{{ $request->status->tagClass() }}">{{ $request->status->label() }}</span>
                            </a>
                        @empty
                            <p class="pu-empty">No requests in this workflow yet.</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>

        <nav class="pu-ledger" aria-label="Related records">
            <a href="{{ \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelEmployeeResource::getUrl('index') }}"><span>Employee Equipment Records</span><strong>Search records</strong></a>
            <a href="{{ \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelAssignmentResource::getUrl('index', ['tableFilters' => ['expiration' => ['value' => 'soon']]]) }}"><span>Expiring Soon</span><strong>{{ $expiringSoon }}</strong></a>
            <a href="{{ \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelAssignmentResource::getUrl('index', ['tableFilters' => ['expiration' => ['value' => 'expired']]]) }}"><span>Expired</span><strong>{{ $expired }}</strong></a>
            <a href="/admin/uniforms"><span>Uniform Inventory Low Stock</span><strong>{{ $lowStock }}</strong></a>
            <a href="/admin/employee-equipment-requests"><span>Active Legacy Requests</span><strong>{{ $legacyOpen }}</strong></a>
        </nav>
    </div>
</x-filament-panels::page>
