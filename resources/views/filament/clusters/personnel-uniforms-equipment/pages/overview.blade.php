<x-filament-panels::page>
    <div class="pu-hero"><div><span>SUPPORT SERVICES · CHAIN OF CUSTODY</span><h2>Personally issued uniforms and firefighting equipment</h2><p>One queue from officer or member submission through review, ordering, arrival, pickup, fulfillment, assignment, and expiration.</p></div><a href="{{ \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource::getUrl('index') }}">Manage all requests</a></div>

    <div class="pu-workstreams">
        @foreach([['Uniform Requests', $uniform, $recentUniform, 'uniform'], ['Equipment Requests', $equipment, $recentEquipment, 'equipment']] as [$title, $stats, $recent, $type])
            <section class="pu-stream pu-stream-{{ $type }}">
                <header><div><span>{{ strtoupper($type) }} WORKFLOW</span><h3>{{ $title }}</h3></div><a href="{{ \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource::getUrl('index', ['tableFilters' => ['type' => ['value' => $type]]]) }}">Manage →</a></header>
                <div class="pu-metrics">
                    <div><strong>{{ $stats['open'] }}</strong><small>Open</small></div>
                    <div><strong>{{ $stats['needs_information'] }}</strong><small>Needs info</small></div>
                    <div><strong>{{ $stats['ordered'] }}</strong><small>Ordered</small></div>
                    <div><strong>{{ $type === 'equipment' ? $stats['arrived'] : $stats['ready'] }}</strong><small>{{ $type === 'equipment' ? 'Arrived' : 'Ready' }}</small></div>
                </div>
                <div class="pu-recent">
                    @forelse($recent as $request)
                        <a href="{{ \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource::getUrl('view', ['record' => $request]) }}"><span><strong>{{ $request->request_number }}</strong><small>{{ $request->beneficiary_name }}</small></span><em>{{ $request->status->label() }}</em></a>
                    @empty
                        <p>No requests in this workflow yet.</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    <div class="pu-ledger">
        <a href="{{ \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelEmployeeResource::getUrl('index') }}"><span>Employee Equipment Records</span><strong>Search records →</strong></a>
        <a href="{{ \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelAssignmentResource::getUrl('index', ['tableFilters' => ['expiration' => ['value' => 'soon']]]) }}"><span>Expiring Soon</span><strong>{{ $expiringSoon }}</strong></a>
        <a href="{{ \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelAssignmentResource::getUrl('index', ['tableFilters' => ['expiration' => ['value' => 'expired']]]) }}"><span>Expired</span><strong>{{ $expired }}</strong></a>
        <a href="/admin/uniforms"><span>Uniform Inventory Low Stock</span><strong>{{ $lowStock }}</strong></a>
        <a href="/admin/employee-equipment-requests"><span>Active Legacy Requests</span><strong>{{ $legacyOpen }}</strong></a>
    </div>

    <style>
        .pu-hero{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.5rem;border-radius:1rem;background:#0f2742;color:#fff}.pu-hero span,.pu-stream header span{font-size:.68rem;font-weight:900;letter-spacing:.12em;color:#93c5fd}.pu-hero h2{margin:.3rem 0 0;font-size:1.35rem;font-weight:800}.pu-hero p{max-width:50rem;margin:.45rem 0 0;color:#cbd5e1;font-size:.85rem;line-height:1.55}.pu-hero>a{display:inline-flex;min-height:3rem;align-items:center;flex-shrink:0;border-radius:.75rem;background:#c2410c;padding:.7rem 1rem;color:#fff;font-weight:800}.pu-workstreams{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem}.pu-stream{overflow:hidden;border:1px solid #e7e5e4;border-top:4px solid #2563eb;border-radius:1rem;background:#fff}.pu-stream-equipment{border-top-color:#d97706}.pu-stream header{display:flex;align-items:center;justify-content:space-between;padding:1.2rem;border-bottom:1px solid #e7e5e4;background:#fafaf9}.pu-stream header h3{margin:.25rem 0 0;color:#0f172a;font-size:1.05rem;font-weight:800}.pu-stream header>a{display:inline-flex;min-height:3rem;align-items:center;color:#1d4ed8;font-size:.8rem;font-weight:800}.pu-metrics{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid #e7e5e4}.pu-metrics div{padding:1rem;border-right:1px solid #e7e5e4;text-align:center}.pu-metrics div:last-child{border:0}.pu-metrics strong,.pu-metrics small{display:block}.pu-metrics strong{color:#0f172a;font-size:1.5rem}.pu-metrics small{margin-top:.25rem;color:#78716c;font-size:.65rem;font-weight:700;text-transform:uppercase}.pu-recent>a{display:flex;min-height:3.8rem;align-items:center;justify-content:space-between;gap:1rem;padding:.75rem 1.1rem;border-bottom:1px solid #f5f5f4}.pu-recent strong,.pu-recent small{display:block}.pu-recent strong{color:#0f172a;font-size:.8rem}.pu-recent small{margin-top:.15rem;color:#78716c;font-size:.72rem}.pu-recent em{border-radius:999px;background:#dbeafe;padding:.25rem .55rem;color:#1e3a8a;font-size:.65rem;font-style:normal;font-weight:800}.pu-recent p{padding:1.5rem;color:#78716c}.pu-ledger{display:grid;grid-template-columns:repeat(5,1fr);gap:.75rem;margin-top:1rem}.pu-ledger a{display:flex;min-height:6rem;flex-direction:column;justify-content:space-between;padding:1rem;border:1px solid #e7e5e4;border-radius:.85rem;background:#fff;color:#57534e}.pu-ledger a:hover{border-color:#93c5fd}.pu-ledger span{font-size:.75rem;font-weight:700}.pu-ledger strong{color:#0f172a;font-size:1.15rem}@media(max-width:1000px){.pu-workstreams{grid-template-columns:1fr}.pu-ledger{grid-template-columns:repeat(2,1fr)}}@media(max-width:640px){.pu-hero{align-items:stretch;flex-direction:column}.pu-hero>a{justify-content:center}.pu-metrics{grid-template-columns:repeat(2,1fr)}.pu-ledger{grid-template-columns:1fr}}
    </style>
</x-filament-panels::page>
