<x-filament-panels::page>
    <div class="er-id"><div><span>PERSONNEL EQUIPMENT RECORD</span><h2>{{ $employee->name }}</h2><p>{{ $employee->rank }} · Employee ID {{ $employee->employee_id }}</p></div></div>
    @php $sections = [['Active Uniforms', $activeUniforms], ['Active Firefighting Equipment', $activeEquipment], ['Expiring Soon', $expiringSoon], ['Expired', $expired], ['History', $history]]; @endphp
    <div class="er-grid">
        @foreach($sections as [$title, $items])
            <section class="er-section {{ $title === 'Expired' ? 'er-alert' : '' }}">
                <header><h3>{{ $title }}</h3><span>{{ $items->count() }}</span></header>
                @forelse($items as $item)
                    <a href="{{ \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelAssignmentResource::getUrl('edit', ['record' => $item]) }}" class="er-row"><span><strong>{{ $item->item_description }}</strong><small>{{ $item->category }} · Qty {{ $item->quantity }}</small></span><em>{{ $item->expires_at ? 'Expires '.$item->expires_at->format('M j, Y') : ($item->issued_at ? 'Issued '.$item->issued_at->format('M j, Y') : 'No issue date') }}</em></a>
                @empty <p class="er-empty">No records in this section.</p> @endforelse
            </section>
        @endforeach
    </div>
    <section class="er-section er-requests"><header><h3>Requests</h3><span>{{ $requests->count() + $legacyRequests->count() }}</span></header>
        @foreach($requests as $request)<a href="{{ \App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource::getUrl('view', ['record' => $request]) }}" class="er-row"><span><strong>{{ $request->request_number }} · {{ $request->type->label() }}</strong><small>{{ $request->items->pluck('item_name')->join(', ') }}</small></span><em>{{ $request->status->label() }}</em></a>@endforeach
        @foreach($legacyRequests as $legacy)<a href="/admin/employee-equipment-requests/{{ $legacy->id }}" class="er-row"><span><strong>Legacy request · {{ $legacy->created_at->format('M j, Y') }}</strong><small>{{ \Illuminate\Support\Str::limit($legacy->requested_items, 100) }}</small></span><em>{{ $legacy->status }}</em></a>@endforeach
        @if($requests->isEmpty() && $legacyRequests->isEmpty())<p class="er-empty">No request history.</p>@endif
    </section>
    <style>.er-id{padding:1.35rem;border-radius:1rem;background:#0f2742;color:#fff}.er-id span{font-size:.68rem;font-weight:900;letter-spacing:.12em;color:#93c5fd}.er-id h2{margin:.3rem 0 0;font-size:1.3rem;font-weight:800}.er-id p{margin:.3rem 0 0;color:#cbd5e1}.er-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem}.er-section{overflow:hidden;border:1px solid #e7e5e4;border-radius:1rem;background:#fff}.er-section header{display:flex;align-items:center;justify-content:space-between;padding:.9rem 1rem;border-bottom:1px solid #e7e5e4;background:#fafaf9}.er-section h3{font-size:.82rem;font-weight:900;color:#0f172a;text-transform:uppercase;letter-spacing:.05em}.er-section header span{display:flex;width:1.6rem;height:1.6rem;align-items:center;justify-content:center;border-radius:50%;background:#e0e7ff;color:#1e3a8a;font-size:.7rem;font-weight:900}.er-alert{border-color:#fecaca}.er-alert header{background:#fef2f2}.er-row{display:flex;min-height:4rem;align-items:center;justify-content:space-between;gap:1rem;padding:.8rem 1rem;border-bottom:1px solid #f5f5f4}.er-row:hover{background:#fafaf9}.er-row strong,.er-row small{display:block}.er-row strong{color:#0f172a;font-size:.8rem}.er-row small{margin-top:.2rem;color:#78716c;font-size:.7rem}.er-row em{color:#57534e;font-size:.7rem;font-style:normal;text-align:right}.er-empty{padding:1.3rem;color:#78716c;font-size:.8rem}.er-requests{margin-top:1rem}@media(max-width:850px){.er-grid{grid-template-columns:1fr}}@media(max-width:560px){.er-row{align-items:flex-start;flex-direction:column}.er-row em{text-align:left}}</style>
</x-filament-panels::page>
